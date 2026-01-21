<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Create;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Exception\QuotaExceededException;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\QueueDrainService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * MessageHandler que consume CreatePlaylistMessage desde RabbitMQ
 * y delega la lógica al CreatePlaylistService
 */
#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class CreatePlaylistMessageHandler
{
    private const QUOTA_COST = 50; // Costo de crear una playlist en YouTube

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
            $this->quotaService->checkQuotaAvailability($message->getAccountId(), self::QUOTA_COST);

            // 2. Convertir Message → Request (DTO de negocio)
            $request = new CreatePlaylistRequest(
                accountId: $message->getAccountId(),
                title: $message->getTitle(),
                description: $message->getDescription(),
                privacy: $message->getPrivacy()
            );

            // 3. Ejecutar el caso de uso (reutiliza el Service)
            $response = $this->createPlaylistService->__invoke($request);

            // 4. Log API Response
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->createQueryBuilder()
                ->field('_id')->equals($message->getAccountId())
                ->getQuery()->getSingleResult();

            if (!$account) {
                $account = $this->documentManager->getRepository(YoutubeAccount::class)
                    ->findOneBy(['accountName' => $message->getAccountId()]);
            }

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlist.create',
                    [
                        'title' => $message->getTitle(),
                        'description' => $message->getDescription(),
                        'privacy' => $message->getPrivacy(),
                    ],
                    [
                        'playlist_id' => $response->playlist->getId(),
                        'youtube_id' => $response->playlist->getYoutubeId(),
                    ],
                    true,
                    null,
                    null,
                    200
                );
            }

            // 5. Consumir quota DESPUÉS de éxito
            $this->quotaService->consumeQuota(
                $message->getAccountId(),
                self::QUOTA_COST,
                'playlist.create',
                $response->playlist->getId()
            );

            $this->logger->info('[PlaylistHexagonal] Playlist created successfully', [
                'playlist_id' => $response->playlist->getId(),
                'youtube_id' => $response->playlist->getYoutubeId(),
                'quota_consumed' => self::QUOTA_COST,
            ]);

        } catch (QuotaExceededException $e) {
            // Quota local agotada → Mover a cola de espera
            $this->logger->warning('[PlaylistHexagonal] Local quota exceeded, draining queue', [
                'account_id' => $message->getAccountId(),
            ]);

            $this->queueDrainService->drainEventsQueue();
            $this->queueDrainService->moveToWaitingQueue($message);

        } catch (\Google_Service_Exception $e) {
            // Log API error response
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->createQueryBuilder()
                ->field('_id')->equals($message->getAccountId())
                ->getQuery()->getSingleResult();

            if (!$account) {
                $account = $this->documentManager->getRepository(YoutubeAccount::class)
                    ->findOneBy(['accountName' => $message->getAccountId()]);
            }

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlist.create',
                    [
                        'title' => $message->getTitle(),
                        'description' => $message->getDescription(),
                        'privacy' => $message->getPrivacy(),
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    [
                        'code' => $e->getCode(),
                        'errors' => $e->getErrors(),
                    ],
                    $e->getCode()
                );
            }

            // Error de YouTube API
            if ($e->getCode() === 429) {
                // YouTube dice que no hay quota → Sincronizar y drenar
                $this->logger->error('[PlaylistHexagonal] YouTube quota exceeded (429), syncing and draining', [
                    'account_id' => $message->getAccountId(),
                    'error' => $e->getMessage(),
                ]);

                $this->quotaService->forceQuotaExhaustion($message->getAccountId());
                $this->queueDrainService->drainEventsQueue();
                $this->queueDrainService->moveToWaitingQueue($message);

            } else {
                // Otro error de YouTube
                $this->logger->error('[PlaylistHexagonal] YouTube API error', [
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);
                throw $e; // Re-lanzar para retry de Messenger
            }

        } catch (\Exception $e) {
            // Log general error
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->createQueryBuilder()
                ->field('_id')->equals($message->getAccountId())
                ->getQuery()->getSingleResult();

            if (!$account) {
                $account = $this->documentManager->getRepository(YoutubeAccount::class)
                    ->findOneBy(['accountName' => $message->getAccountId()]);
            }

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlist.create',
                    [
                        'title' => $message->getTitle(),
                        'description' => $message->getDescription(),
                        'privacy' => $message->getPrivacy(),
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    [
                        'trace' => $e->getTraceAsString(),
                    ],
                    500
                );
            }

            // Error inesperado
            $this->logger->error('[PlaylistHexagonal] Unexpected error creating playlist', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Re-lanzar para retry de Messenger
        }
    }
}
