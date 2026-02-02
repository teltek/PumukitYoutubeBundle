<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Infrastructure\Middleware;

use Pumukit\YoutubeBundle\QuotaHexagonal\Application\Waiting\WaitingMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Middleware que intercepta mensajes y verifica la quota antes de procesarlos.
 * Si la quota está excedida, mueve el mensaje a la cola de espera (quota.waiting).
 */
class QuotaCheckMiddleware implements MiddlewareInterface
{
    // Map de mensajes a operaciones de YouTube y sus cuentas
    private const MESSAGE_TO_OPERATION = [
        // Video operations
        'UploadVideoMessage' => ['operation' => 'videos.insert', 'quotaCost' => 1600],
        'UploadYoutubeVideoMessage' => ['operation' => 'videos.insert', 'quotaCost' => 1600],
        'UpdateVideoMessage' => ['operation' => 'videos.update', 'quotaCost' => 50],
        'DeleteVideoMessage' => ['operation' => 'videos.delete', 'quotaCost' => 50],
        'UpdatePublicationMessage' => ['operation' => 'videos.update', 'quotaCost' => 50],
        
        // Playlist operations (PlaylistHexagonal)
        'CreatePlaylistMessage' => ['operation' => 'playlists.insert', 'quotaCost' => 50],
        'UpdatePlaylistMessage' => ['operation' => 'playlists.update', 'quotaCost' => 50],
        'DeletePlaylistMessage' => ['operation' => 'playlists.delete', 'quotaCost' => 50],
        'AddVideoMessage' => ['operation' => 'playlistItems.insert', 'quotaCost' => 50],
        'RemoveVideoMessage' => ['operation' => 'playlistItems.delete', 'quotaCost' => 50],
        
        // Caption operations (CaptionHexagonal)
        'UploadCaptionMessage' => ['operation' => 'captions.insert', 'quotaCost' => 50],
        'DeleteCaptionMessage' => ['operation' => 'captions.delete', 'quotaCost' => 50],
    ];

    public function __construct(
        private readonly QuotaService $quotaService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        
        // Solo verificar quota para mensajes que no vengan de la cola de espera
        // y que no sean ya mensajes de espera
        if ($message instanceof WaitingMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Solo verificar si el mensaje fue recibido de un transport (no si es dispatch inicial)
        $receivedStamp = $envelope->last(ReceivedStamp::class);
        if (!$receivedStamp) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Verificar si viene de la cola de fallos (no re-verificar)
        if ($envelope->last(SentToFailureTransportStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Obtener información de la operación
        $messageClass = (new \ReflectionClass($message))->getShortName();
        $operationInfo = self::MESSAGE_TO_OPERATION[$messageClass] ?? null;

        if (!$operationInfo) {
            // Si no es una operación de YouTube, continuar normalmente
            return $stack->next()->handle($envelope, $stack);
        }

        // Extraer accountId del mensaje
        $accountId = $this->extractAccountId($message);
        if (!$accountId) {
            $this->logger->warning('[QuotaCheckMiddleware] Could not extract accountId from message', [
                'messageClass' => $messageClass,
            ]);
            return $stack->next()->handle($envelope, $stack);
        }

        // Verificar quota ANTES de procesar
        try {
            $quotaCheck = $this->quotaService->checkQuota($accountId, $operationInfo['operation']);
            
            if (!$quotaCheck['canProceed']) {
                // QUOTA EXCEDIDA - Mover a cola de espera
                $this->logger->warning('[QuotaCheckMiddleware] Quota exceeded, moving to waiting queue', [
                    'accountId' => $accountId,
                    'operation' => $operationInfo['operation'],
                    'quotaCost' => $operationInfo['quotaCost'],
                    'available' => $quotaCheck['available'],
                    'required' => $quotaCheck['required'],
                    'messageClass' => $messageClass,
                ]);

                // Registrar en el panel de quota que se rechazó por falta de quota
                // (Simplificado: sin necesidad de findAccountTag que no existe)
                try {
                    $this->logger->warning('[QuotaCheckMiddleware] Quota rejected - will log in waiting queue handler', [
                        'accountId' => $accountId,
                        'operation' => $operationInfo['operation'],
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('[QuotaCheckMiddleware] Failed to log quota rejection', [
                        'error' => $e->getMessage(),
                    ]);
                }

                // Crear WaitingMessage y enviarlo a la cola de espera
                $waitingMessage = new WaitingMessage(
                    accountId: $accountId,
                    originalMessage: $message,
                    quotaCost: $operationInfo['quotaCost'],
                    operation: $operationInfo['operation']
                );

                $this->messageBus->dispatch($waitingMessage);

                $this->logger->info('[QuotaCheckMiddleware] Message moved to waiting queue', [
                    'accountId' => $accountId,
                    'messageClass' => $messageClass,
                ]);

                // Lanzar excepción NO recuperable para que Messenger haga ACK del mensaje original
                // y lo elimine de la cola "events" (evita re-procesamiento infinito)
                throw new UnrecoverableMessageHandlingException(
                    sprintf(
                        'Quota exceeded for account %s. Message moved to waiting queue.',
                        $accountId
                    )
                );
            }

            // Quota disponible - continuar con el procesamiento normal
            $this->logger->info('[QuotaCheckMiddleware] Quota check passed', [
                'accountId' => $accountId,
                'operation' => $operationInfo['operation'],
                'available' => $quotaCheck['available'],
                'required' => $quotaCheck['required'],
            ]);

            // Procesar el mensaje
            $result = $stack->next()->handle($envelope, $stack);

            // Si llegamos aquí, el mensaje se procesó exitosamente
            // Registrar el consumo de quota
            $this->quotaService->consumeQuota(
                $accountId,
                $operationInfo['operation'],
                [
                    'messageClass' => $messageClass,
                    'processedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );

            return $result;

        } catch (UnrecoverableMessageHandlingException $e) {
            // Re-lanzar la excepción de quota para que Messenger la maneje correctamente
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('[QuotaCheckMiddleware] Error checking quota', [
                'accountId' => $accountId,
                'error' => $e->getMessage(),
            ]);
            // En caso de error, no consumir quota y continuar (fail open)
            throw $e;
        }
    }

    /**
     * Extrae el accountId del mensaje usando reflexión
     */
    private function extractAccountId(object $message): ?string
    {
        try {
            if (method_exists($message, 'getAccountId')) {
                return $message->getAccountId();
            }

            // Para mensajes que tienen multimediaObjectId, necesitamos buscarlo
            if (method_exists($message, 'getMultimediaObjectId')) {
                // En este caso, deberíamos obtener el accountId desde el MM object
                // Pero para evitar inyectar DocumentManager aquí, dejamos que el handler lo maneje
                return null;
            }

            // Intentar acceder a propiedad accountId directamente
            $reflection = new \ReflectionClass($message);
            if ($reflection->hasProperty('accountId')) {
                $property = $reflection->getProperty('accountId');
                $property->setAccessible(true);
                return $property->getValue($message);
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('[QuotaCheckMiddleware] Error extracting accountId', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
