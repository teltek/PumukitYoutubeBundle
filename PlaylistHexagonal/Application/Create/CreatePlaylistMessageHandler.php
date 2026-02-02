<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Create;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Exception\QuotaExceededException;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeApiResponse;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\QueueDrainService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * MessageHandler que consume CreatePlaylistMessage desde RabbitMQ
 * y delega la lógica al CreatePlaylistService
 */
#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class CreatePlaylistMessageHandler
{
    private const OPERATION_TYPE = 'create_playlist'; // Tipo de operación para quota

    public function __construct(
        private CreatePlaylistService $createPlaylistService,
        private QuotaService $quotaService,
        private QueueDrainService $queueDrainService,
        private DocumentManager $documentManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(CreatePlaylistMessage $message): void
    {
        $this->logger->info('[PlaylistHexagonal] Processing CreatePlaylistMessage from queue', [
            'account_id' => $message->getAccountId(),
            'title' => $message->getTitle(),
        ]);

        try {
            // 1. Verificar quota ANTES de ejecutar
            $this->quotaService->checkQuotaAvailability($message->getAccountId(), self::OPERATION_TYPE);

            // 2. Convertir Message → Request (DTO de negocio)
            $request = new CreatePlaylistRequest(
                accountId: $message->getAccountId(),
                title: $message->getTitle(),
                description: $message->getDescription(),
                privacy: $message->getPrivacy()
            );

            // 3. Ejecutar el caso de uso (reutiliza el Service)
            $response = $this->createPlaylistService->__invoke($request);

            $this->logger->info('[PlaylistHexagonal] Playlist created successfully', [
                'playlist_id' => $response->playlist->getId(),
                'youtube_id' => $response->playlist->getYoutubeId(),
                'quota_consumed' => 50,
            ]);

            // 4. Consumir quota DESPUÉS de éxito
            $this->quotaService->consumeQuota(
                $message->getAccountId(),
                'playlist.create',
                [
                    'playlist_id' => $response->playlist->getId(),
                    'title' => $message->getTitle(),
                ]
            );

            $this->logger->info('[PlaylistHexagonal] Quota consumed', [
                'account_id' => $message->getAccountId(),
                'cost' => 50,
            ]);

            // 5. Registrar operación exitosa en YoutubeApiResponse para panel de quota
            $apiResponse = new YoutubeApiResponse(
                $message->getAccountId(),
                'playlist.create',
                50,
                [
                    'title' => $message->getTitle(),
                    'description' => $message->getDescription(),
                    'privacy' => $message->getPrivacy(),
                ]
            );
            $apiResponse->markAsSuccess(
                [
                    'playlist_id' => $response->playlist->getId(),
                    'youtube_id' => $response->playlist->getYoutubeId(),
                ],
                200
            );
            $this->documentManager->persist($apiResponse);
            $this->documentManager->flush();

        } catch (QuotaExceededException $e) {
            // Quota local agotada → Mover a cola de espera
            $this->logger->warning('[PlaylistHexagonal] Local quota exceeded, draining queue', [
                'account_id' => $message->getAccountId(),
            ]);

            $this->queueDrainService->drainEventsQueue($message->getAccountId());
            $this->queueDrainService->moveToWaitingQueue($message, $message->getAccountId());

        } catch (\Google_Service_Exception $e) {
            // Log API error response
            $this->logger->error('[PlaylistHexagonal] Google API error', [
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);

            // Registrar error en YoutubeApiResponse
            $apiResponse = new YoutubeApiResponse(
                $message->getAccountId(),
                'playlist.create',
                50,
                [
                    'title' => $message->getTitle(),
                    'description' => $message->getDescription(),
                    'privacy' => $message->getPrivacy(),
                ]
            );
            $apiResponse->markAsFailure(
                $e->getMessage(),
                ['errors' => $e->getErrors() ?? []],
                $e->getCode()
            );
            $this->documentManager->persist($apiResponse);
            $this->documentManager->flush();

            // Error de YouTube API
            if ($e->getCode() === 429) {
                // YouTube dice que no hay quota → Sincronizar y drenar
                $this->logger->error('[PlaylistHexagonal] YouTube quota exceeded (429), syncing and draining', [
                    'account_id' => $message->getAccountId(),
                    'error' => $e->getMessage(),
                ]);

                $this->quotaService->forceQuotaExhaustion($message->getAccountId());
                $this->queueDrainService->drainEventsQueue($message->getAccountId());
                $this->queueDrainService->moveToWaitingQueue($message, $message->getAccountId());

            } else {
                // Otro error de YouTube
                throw $e; // Re-lanzar para retry de Messenger
            }

        } catch (\Exception $e) {
            // Error inesperado
            $this->logger->error('[PlaylistHexagonal] Unexpected error creating playlist', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Registrar error inesperado en YoutubeApiResponse
            $apiResponse = new YoutubeApiResponse(
                $message->getAccountId(),
                'playlist.create',
                50,
                [
                    'title' => $message->getTitle(),
                    'description' => $message->getDescription(),
                    'privacy' => $message->getPrivacy(),
                ]
            );
            $apiResponse->markAsFailure(
                $e->getMessage(),
                ['trace' => substr($e->getTraceAsString(), 0, 500)],
                500
            );
            $this->documentManager->persist($apiResponse);
            $this->documentManager->flush();

            throw $e; // Re-lanzar para retry de Messenger
        }
    }
}
