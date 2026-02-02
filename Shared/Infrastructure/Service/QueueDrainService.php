<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Infrastructure\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\QuotaHexagonal\Application\Waiting\WaitingMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service to drain the events queue when quota is exceeded.
 * Moves all pending messages to the waiting queue.
 */
class QueueDrainService
{
    private const QUEUE_NAME = 'pumukit.youtube.events';
    
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
        private readonly string $messengerTransportDsn
    ) {
    }

    /**
     * Drain all messages from the events queue and move them to waiting.
     * This is called when a 429 error is detected.
     */
    public function drainEventsQueue(?string $accountId = null): int
    {
        $this->logger->warning('[QueueDrainService] Draining events queue due to quota exceeded', [
            'accountId' => $accountId,
        ]);

        $drainedCount = 0;

        try {
            // Parse RabbitMQ DSN to get connection details
            $parsedDsn = parse_url($this->messengerTransportDsn);
            
            $connection = new \AMQPConnection([
                'host' => $parsedDsn['host'] ?? 'rabbitmq',
                'port' => $parsedDsn['port'] ?? 5672,
                'login' => $parsedDsn['user'] ?? 'admin',
                'password' => $parsedDsn['pass'] ?? 'admin',
            ]);

            $connection->connect();
            $channel = new \AMQPChannel($connection);
            $queue = new \AMQPQueue($channel);
            $queue->setName(self::QUEUE_NAME);
            $queue->setFlags(AMQP_DURABLE);
            $queue->declareQueue();

            $this->logger->info('[QueueDrainService] Queue declared, checking message count', [
                'messageCount' => $queue->declareQueue(),
            ]);

            // Consume all messages from the queue
            while ($envelope = $queue->get()) {
                try {
                    $body = $envelope->getBody();
                    $messageData = json_decode($body, true);

                    if (!$messageData) {
                        $this->logger->error('[QueueDrainService] Failed to decode message', [
                            'body' => $body,
                        ]);
                        $queue->ack($envelope->getDeliveryTag());
                        continue;
                    }

                    // Extract the original message type and body
                    $messageType = $messageData['type'] ?? null;
                    $messageBody = $messageData['body'] ?? [];

                    if (!$messageType) {
                        $this->logger->error('[QueueDrainService] Message type not found');
                        $queue->ack($envelope->getDeliveryTag());
                        continue;
                    }

                    // Skip WaitingMessage (already in waiting state)
                    if (str_contains($messageType, 'WaitingMessage')) {
                        $queue->ack($envelope->getDeliveryTag());
                        continue;
                    }

                    // Reconstruct the original message object just to validate it
                    $originalMessage = $this->reconstructMessage($messageType, $messageBody);

                    if (!$originalMessage) {
                        $this->logger->error('[QueueDrainService] Failed to reconstruct message', [
                            'messageType' => $messageType,
                        ]);
                        $queue->ack($envelope->getDeliveryTag());
                        continue;
                    }

                    // Determine the FQCN and short name for cost/operation lookup
                    $messageClass = $this->getFullClassName($messageType);
                    $shortClassName = class_basename($messageClass);

                    // Create WaitingMessage with class name + raw data (array)
                    $waitingMessage = new WaitingMessage(
                        accountId: $accountId,
                        originalMessageClass: $messageClass,
                        originalMessageData: $messageBody,
                        quotaCost: $this->estimateQuotaCost($shortClassName),
                        operation: $this->getOperationName($shortClassName)
                    );

                    $this->messageBus->dispatch($waitingMessage);
                    
                    // ACK the original message (remove from events queue)
                    $queue->ack($envelope->getDeliveryTag());
                    
                    $drainedCount++;
                    
                    $this->logger->info('[QueueDrainService] Message moved to waiting queue', [
                        'messageType' => $messageType,
                        'drainedCount' => $drainedCount,
                    ]);

                } catch (\Exception $e) {
                    $this->logger->error('[QueueDrainService] Error processing message', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // NACK the message to keep it in queue
                    $queue->nack($envelope->getDeliveryTag());
                }
            }

            $connection->disconnect();

            $this->logger->warning('[QueueDrainService] Queue drain completed', [
                'accountId' => $accountId,
                'drainedCount' => $drainedCount,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[QueueDrainService] Failed to drain queue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $drainedCount;
    }

    /**
     * Reconstruct message object from type and body.
     */
    private function reconstructMessage(string $messageType, array $body): ?object
    {
        try {
            // Map short class name to full class name
            $messageClass = $this->getFullClassName($messageType);
            
            if (!class_exists($messageClass)) {
                $this->logger->error('[QueueDrainService] Message class not found', [
                    'messageType' => $messageType,
                    'messageClass' => $messageClass,
                ]);
                return null;
            }

            // Use reflection to create instance
            $reflection = new \ReflectionClass($messageClass);
            $constructor = $reflection->getConstructor();
            
            if (!$constructor) {
                return new $messageClass();
            }

            // Extract constructor parameters from body
            $params = [];
            foreach ($constructor->getParameters() as $param) {
                $paramName = $param->getName();
                $params[] = $body[$paramName] ?? null;
            }

            return $reflection->newInstanceArgs($params);

        } catch (\Exception $e) {
            $this->logger->error('[QueueDrainService] Error reconstructing message', [
                'messageType' => $messageType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get full class name from short name.
     */
    private function getFullClassName(string $shortName): string
    {
        // If it's already a FQCN, return as-is
        if (str_contains($shortName, '\\')) {
            return $shortName;
        }

        // Candidate namespaces (hexagonal first)
        $candidates = [
            'Pumukit\\YoutubeBundle\\CaptionHexagonal\\Application\\Upload\\',
            'Pumukit\\YoutubeBundle\\VideoHexagonal\\Application\\Upload\\',
            'Pumukit\\YoutubeBundle\\PlaylistHexagonal\\Application\\AddVideo\\',
            'Pumukit\\YoutubeBundle\\PlaylistHexagonal\\Application\\Create\\',
            'Pumukit\\YoutubeBundle\\PlaylistHexagonal\\Application\\Update\\',
            'Pumukit\\YoutubeBundle\\PlaylistHexagonal\\Application\\RemoveVideo\\',
            'Pumukit\\YoutubeBundle\\AccountHexagonal\\Application\\Create\\',
            'Pumukit\\YoutubeBundle\\AccountHexagonal\\Application\\Update\\',
            'Pumukit\\YoutubeBundle\\QuotaHexagonal\\Application\\Waiting\\',
            // Legacy application namespace as fallback
            'Pumukit\\YoutubeBundle\\Application\\Message\\',
        ];

        foreach ($candidates as $ns) {
            $fqcn = $ns . $shortName;
            if (class_exists($fqcn)) {
                return $fqcn;
            }
        }

        // As a last resort, return the legacy root namespace (may not exist)
        return 'Pumukit\\YoutubeBundle\\Application\\Message\\' . $shortName;
    }

    /**
     * Estimate quota cost for message type.
     */
    private function estimateQuotaCost(string $messageType): int
    {
        $costs = [
            'UploadVideoMessage' => 1600,
            'UploadYoutubeVideoMessage' => 1600,
            'UpdateVideoMessage' => 50,
            'DeleteVideoMessage' => 50,
            'UpdatePublicationMessage' => 50,
            'UploadCaptionMessage' => 400,
            'UploadCaptionsMessage' => 400,
            'AddVideoMessage' => 50,
            'AddVideoToPlaylistMessage' => 50,
        ];

        return $costs[$messageType] ?? 50;
    }

    /**
     * Get operation name for message type.
     */
    private function getOperationName(string $messageType): string
    {
        $operations = [
            'UploadVideoMessage' => 'videos.insert',
            'UploadYoutubeVideoMessage' => 'videos.insert',
            'UpdateVideoMessage' => 'videos.update',
            'DeleteVideoMessage' => 'videos.delete',
            'UpdatePublicationMessage' => 'videos.update',
            'UploadCaptionMessage' => 'captions.insert',
            'UploadCaptionsMessage' => 'captions.insert',
            'AddVideoMessage' => 'playlistItem.insert',
            'AddVideoToPlaylistMessage' => 'playlistItem.insert',
        ];

        return $operations[$messageType] ?? 'unknown';
    }

    /**
     * Move a single message to the waiting queue when quota is exceeded.
     * This wraps the original message with metadata in a WaitingMessage.
     */
    public function moveToWaitingQueue(object $originalMessage, string $accountId): void
    {
        try {
            $messageClass = get_class($originalMessage);
            $messageData = $this->serializeMessage($originalMessage);
            $shortClassName = class_basename($messageClass);
            $quotaCost = $this->estimateQuotaCost($shortClassName);
            $operation = $this->getOperationName($shortClassName);

            $waitingMessage = new WaitingMessage(
                accountId: $accountId,
                originalMessageClass: $messageClass,
                originalMessageData: $messageData,
                quotaCost: $quotaCost,
                operation: $operation
            );

            $this->messageBus->dispatch($waitingMessage);

            $this->logger->info('[QueueDrainService] Message moved to waiting queue', [
                'messageClass' => $messageClass,
                'accountId' => $accountId,
                'quotaCost' => $quotaCost,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[QueueDrainService] Error moving message to waiting queue', [
                'messageClass' => get_class($originalMessage),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Serialize a message object to array for storage in WaitingMessage.
     */
    private function serializeMessage(object $message): array
    {
        try {
            $reflection = new \ReflectionClass($message);
            $data = [];

            foreach ($reflection->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED) as $property) {
                $property->setAccessible(true);
                $value = $property->getValue($message);
                
                // Handle DateTimeImmutable and DateTime
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('c');
                } elseif (is_object($value) && !($value instanceof \stdClass)) {
                    // Skip complex objects
                    continue;
                }
                
                $data[$property->getName()] = $value;
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('[QueueDrainService] Error serializing message', [
                'messageClass' => get_class($message),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
