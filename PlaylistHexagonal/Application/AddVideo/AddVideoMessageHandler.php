<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\AddVideo;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\QueueDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class AddVideoMessageHandler
{
    private AddVideoService $addVideoService;
    private QuotaService $quotaService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        AddVideoService $addVideoService,
        QuotaService $quotaService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->addVideoService = $addVideoService;
        $this->quotaService = $quotaService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(AddVideoMessage $message): void
    {
        $this->logger->info('[PlaylistHexagonal] Processing AddVideoMessage', [
            'playlistId' => $message->getPlaylistId(),
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        try {
            $accountId = 'default'; // TODO: Get from playlist

            $this->quotaService->checkQuotaAvailability($accountId, 50);

            $request = new AddVideoRequest(
                $message->getPlaylistId(),
                $message->getMultimediaObjectId()
            );

            $response = $this->addVideoService->__invoke($request);

            // Log API Response
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->createQueryBuilder()
                ->field('_id')->equals($accountId)
                ->getQuery()->getSingleResult();

            if (!$account) {
                $account = $this->documentManager->getRepository(YoutubeAccount::class)
                    ->findOneBy(['accountName' => $accountId]);
            }

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlist.addVideo',
                    [
                        'playlistId' => $message->getPlaylistId(),
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                    ],
                    [
                        'playlistId' => $response->getPlaylistId(),
                        'videoId' => $response->getVideoId(),
                    ],
                    true,
                    null,
                    null,
                    200
                );
            }

            $this->quotaService->consumeQuota($accountId, 50, 'playlist.addVideo');

            $this->logger->info('[PlaylistHexagonal] Video added to playlist successfully', [
                'playlistId' => $response->getPlaylistId(),
                'videoId' => $response->getVideoId(),
            ]);
        } catch (\Google_Service_Exception $e) {
            // Log API error response
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->createQueryBuilder()
                ->field('_id')->equals($accountId ?? 'default')
                ->getQuery()->getSingleResult();

            if (!$account) {
                $account = $this->documentManager->getRepository(YoutubeAccount::class)
                    ->findOneBy(['accountName' => $accountId ?? 'default']);
            }

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlist.addVideo',
                    [
                        'playlistId' => $message->getPlaylistId(),
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
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

            if ($e->getCode() === 429) {
                $this->logger->warning('[PlaylistHexagonal] Quota exceeded');
                $this->quotaService->forceQuotaExhaustion($accountId ?? 'default');
                $this->queueDrainService->drainEventsQueue();
                $this->queueDrainService->moveToWaitingQueue($message);
            } else {
                throw $e;
            }
        } catch (\Exception $e) {
            // Log general error
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->createQueryBuilder()
                ->field('_id')->equals($accountId ?? 'default')
                ->getQuery()->getSingleResult();

            if (!$account) {
                $account = $this->documentManager->getRepository(YoutubeAccount::class)
                    ->findOneBy(['accountName' => $accountId ?? 'default']);
            }

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlist.addVideo',
                    [
                        'playlistId' => $message->getPlaylistId(),
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
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

            $this->logger->error('[PlaylistHexagonal] Failed to add video to playlist', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
