<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Exception\QuotaExceededException;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\QueueDrainService;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\PlaylistRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class DeletePlaylistMessageHandler
{
    private const QUOTA_COST = 50;

    public function __construct(
        private DeletePlaylistService $deletePlaylistService,
        private PlaylistRepositoryInterface $playlistRepository,
        private QuotaService $quotaService,
        private QueueDrainService $queueDrainService,
        private DocumentManager $documentManager,
        private LoggerInterface $logger
    ) {}

    public function __invoke(DeletePlaylistMessage $message): void
    {
        $this->logger->info('[PlaylistHexagonal] Processing DeletePlaylistMessage', [
            'playlist_id' => $message->getPlaylistId(),
        ]);

        try {
            // Obtener playlist para conocer el accountId
            $playlist = $this->playlistRepository->find($message->getPlaylistId());
            if (!$playlist) {
                throw new \RuntimeException('Playlist not found: ' . $message->getPlaylistId());
            }

            // Verificar quota
            $this->quotaService->checkQuotaAvailability($playlist->getAccountId(), self::QUOTA_COST);

            // Ejecutar
            $request = new DeletePlaylistRequest($message->getPlaylistId());
            $response = $this->deletePlaylistService->__invoke($request);

            // Log API Response
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->createQueryBuilder()
                ->field('_id')->equals($response->accountId)
                ->getQuery()->getSingleResult();

            if (!$account) {
                $account = $this->documentManager->getRepository(YoutubeAccount::class)
                    ->findOneBy(['accountName' => $response->accountId]);
            }

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlist.delete',
                    [
                        'playlist_id' => $message->getPlaylistId(),
                    ],
                    [
                        'success' => true,
                        'deleted' => true,
                    ],
                    true,
                    null,
                    null,
                    200
                );
            }

            // Consumir quota
            $this->quotaService->consumeQuota(
                $response->accountId,
                self::QUOTA_COST,
                'playlist.delete',
                $message->getPlaylistId()
            );

            $this->logger->info('[PlaylistHexagonal] Playlist deleted successfully');

        } catch (QuotaExceededException $e) {
            $this->logger->warning('[PlaylistHexagonal] Quota exceeded on delete');
            $this->queueDrainService->drainEventsQueue();
            $this->queueDrainService->moveToWaitingQueue($message);

        } catch (\Google_Service_Exception $e) {
            $playlist = $this->playlistRepository->find($message->getPlaylistId());
            
            // Log API error response
            if ($playlist) {
                $account = $this->documentManager->getRepository(YoutubeAccount::class)
                    ->createQueryBuilder()
                    ->field('_id')->equals($playlist->getAccountId())
                    ->getQuery()->getSingleResult();

                if (!$account) {
                    $account = $this->documentManager->getRepository(YoutubeAccount::class)
                        ->findOneBy(['accountName' => $playlist->getAccountId()]);
                }

                if ($account) {
                    $this->quotaService->logApiResponse(
                        $account,
                        'playlist.delete',
                        [
                            'playlist_id' => $message->getPlaylistId(),
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
            }

            if ($e->getCode() === 429) {
                if ($playlist) {
                    $this->quotaService->forceQuotaExhaustion($playlist->getAccountId());
                }
                $this->queueDrainService->drainEventsQueue();
                $this->queueDrainService->moveToWaitingQueue($message);
            } else {
                throw $e;
            }
        }
    }
}
