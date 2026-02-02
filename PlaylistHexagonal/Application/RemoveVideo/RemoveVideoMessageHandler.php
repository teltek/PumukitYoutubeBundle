<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\RemoveVideo;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\QueueDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class RemoveVideoMessageHandler
{
    private RemoveVideoService $removeVideoService;
    private QuotaService $quotaService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        RemoveVideoService $removeVideoService,
        QuotaService $quotaService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->removeVideoService = $removeVideoService;
        $this->quotaService = $quotaService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(RemoveVideoMessage $message): void
    {
        $this->logger->info('[PlaylistHexagonal] Processing RemoveVideoMessage', [
            'playlistItemId' => $message->getPlaylistItemId(),
        ]);

        try {
            $accountId = 'default'; // TODO: Get from playlist

            $this->quotaService->checkQuotaAvailability($accountId, 50);

            $request = new RemoveVideoRequest($message->getPlaylistItemId());

            $response = $this->removeVideoService->__invoke($request);

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
                    'playlist.removeVideo',
                    [
                        'playlistItemId' => $message->getPlaylistItemId(),
                    ],
                    [
                        'playlistItemId' => $response->getPlaylistItemId(),
                    ],
                    true,
                    null,
                    null,
                    200
                );
            }

            $this->quotaService->consumeQuota($accountId, 50, 'playlist.removeVideo');

            $this->logger->info('[PlaylistHexagonal] Video removed from playlist successfully', [
                'playlistItemId' => $response->getPlaylistItemId(),
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
                    'playlist.removeVideo',
                    [
                        'playlistItemId' => $message->getPlaylistItemId(),
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
                    'playlist.removeVideo',
                    [
                        'playlistItemId' => $message->getPlaylistItemId(),
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

            $this->logger->error('[PlaylistHexagonal] Failed to remove video from playlist', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
