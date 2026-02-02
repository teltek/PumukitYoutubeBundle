<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Exception\QuotaExceededException;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeApiResponse;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\QueueDrainService;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\PlaylistRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class DeletePlaylistMessageHandler
{
    private const QUOTA_COST = 50;
    private const OPERATION_TYPE = 'playlist.delete';

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
            $this->quotaService->checkQuotaAvailability($playlist->getAccountId(), self::OPERATION_TYPE);

            // Ejecutar
            $request = new DeletePlaylistRequest($message->getPlaylistId());
            $response = $this->deletePlaylistService->__invoke($request);

            // Log API Response
            $apiResponse = new YoutubeApiResponse(
                $response->accountId,
                self::OPERATION_TYPE,
                self::QUOTA_COST,
                [
                    'playlist_id' => $message->getPlaylistId(),
                ]
            );
            $apiResponse->markAsSuccess(
                [
                    'deleted' => true,
                    'deletedPlaylistId' => $message->getPlaylistId(),
                ],
                200
            );
            $this->documentManager->persist($apiResponse);
            $this->documentManager->flush();

            // Consumir quota
            $this->quotaService->consumeQuota(
                $response->accountId,
                self::OPERATION_TYPE,
                [
                    'playlist_id' => $message->getPlaylistId(),
                ]
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
                $apiResponse = new YoutubeApiResponse(
                    $playlist->getAccountId(),
                    self::OPERATION_TYPE,
                    self::QUOTA_COST,
                    [
                        'playlist_id' => $message->getPlaylistId(),
                    ]
                );
                $apiResponse->markAsFailure(
                    $e->getMessage(),
                    ['errors' => $e->getErrors() ?? []],
                    $e->getCode()
                );
                $this->documentManager->persist($apiResponse);
                $this->documentManager->flush();
            }

            if ($e->getCode() === 429) {
                if ($playlist) {
                    $this->quotaService->forceQuotaExhaustion($playlist->getAccountId());
                }
                $this->queueDrainService->drainEventsQueue($playlist->getAccountId() ?? '');
                $this->queueDrainService->moveToWaitingQueue($message, $playlist->getAccountId() ?? '');
            } else {
                throw $e;
            }
        }
    }
}
