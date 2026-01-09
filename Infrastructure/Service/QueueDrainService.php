<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Infrastructure\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Application\Message\WaitingMessage;
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
    public function drainEventsQueue(string $accountId): int
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

                    // Reconstruct the original message object
                    $originalMessage = $this->reconstructMessage($messageType, $messageBody);
                    
                    if (!$originalMessage) {
                        $this->logger->error('[QueueDrainService] Failed to reconstruct message', [
                            'messageType' => $messageType,
                        ]);
                        $queue->ack($envelope->getDeliveryTag());
                        continue;
                    }

                    // Create WaitingMessage and dispatch to waiting queue
                    $waitingMessage = new WaitingMessage(
                        accountId: $accountId,
                        originalMessage: $originalMessage,
                        quotaCost: $this->estimateQuotaCost($messageType),
                        operation: $this->getOperationName($messageType)
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
        $namespace = 'Pumukit\\YoutubeBundle\\Application\\Message\\';
        
        // Map to subnamespaces
        $subnamespaces = [
            'Video' => ['UploadVideoMessage', 'UpdateVideoMessage', 'DeleteVideoMessage', 'UpdatePublicationMessage', 'UploadYoutubeVideoMessage'],
            'Playlist' => ['CreatePlaylistMessage', 'UpdatePlaylistMessage', 'DeletePlaylistMessage', 'AddVideoToPlaylistMessage', 'RemoveVideoFromPlaylistMessage', 'AssignToPlaylistsMessage', 'UpdatePlaylistItemsMessage', 'SyncPlaylistsMessage'],
            'Caption' => ['UploadCaptionsMessage'],
        ];

        foreach ($subnamespaces as $sub => $messages) {
            if (in_array($shortName, $messages)) {
                return $namespace . $sub . '\\' . $shortName;
            }
        }

        // Default to root namespace
        return $namespace . $shortName;
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
            'CreatePlaylistMessage' => 50,
            'UpdatePlaylistMessage' => 50,
            'DeletePlaylistMessage' => 50,
            'AddVideoToPlaylistMessage' => 50,
            'RemoveVideoFromPlaylistMessage' => 50,
            'AssignToPlaylistsMessage' => 50,
            'UpdatePlaylistItemsMessage' => 50,
            'UploadCaptionsMessage' => 400,
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
            'CreatePlaylistMessage' => 'playlists.insert',
            'UpdatePlaylistMessage' => 'playlists.update',
            'DeletePlaylistMessage' => 'playlists.delete',
            'AddVideoToPlaylistMessage' => 'playlistItems.insert',
            'RemoveVideoFromPlaylistMessage' => 'playlistItems.delete',
            'AssignToPlaylistsMessage' => 'playlistItems.insert',
            'UpdatePlaylistItemsMessage' => 'playlistItems.insert',
            'UploadCaptionsMessage' => 'captions.insert',
        ];

        return $operations[$messageType] ?? 'unknown';
    }
}
