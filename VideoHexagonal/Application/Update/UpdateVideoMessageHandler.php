<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Update;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\QueueDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class UpdateVideoMessageHandler
{
    private UpdateVideoService $updateVideoService;
    private QuotaService $quotaService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        UpdateVideoService $updateVideoService,
        QuotaService $quotaService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->updateVideoService = $updateVideoService;
        $this->quotaService = $quotaService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(UpdateVideoMessage $message): void
    {
        $this->logger->info('[VideoHexagonal] Processing UpdateVideoMessage', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        try {
            // Note: Account ID would need to be passed or fetched
            $accountId = 'default'; // TODO: Get from MultimediaObject

            // 1. Check quota (50 cost for update)
            $this->quotaService->checkQuotaAvailability($accountId, 50);

            // 2. Execute
            $request = new UpdateVideoRequest($message->getMultimediaObjectId());
            $response = $this->updateVideoService->__invoke($request);

            // 3. Log API Response
            $account = $this->findAccountTag($accountId);

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'video.update',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                    ],
                    [
                        'success' => true,
                        'updated' => true,
                    ],
                    true,
                    null,
                    null,
                    200
                );
            }

            // 4. Consume quota
            $this->quotaService->consumeQuota($accountId, 50, 'video.update');

            $this->logger->info('[VideoHexagonal] Video updated successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
        } catch (\Google_Service_Exception $e) {
            // Log API error response
            $account = $this->findAccountTag($accountId ?? 'default');

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'video.update',
                    [
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
                $this->logger->warning('[VideoHexagonal] Quota exceeded');
                $this->quotaService->forceQuotaExhaustion($accountId ?? 'default');
                $this->queueDrainService->drainEventsQueue();
                $this->queueDrainService->moveToWaitingQueue($message);
            } else {
                throw $e;
            }
        } catch (\Exception $e) {
            // Log general error
            $account = $this->findAccountTag($accountId ?? 'default');

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'video.update',
                    [
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

            $this->logger->error('[VideoHexagonal] Failed to update video', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function findAccountTag(string $accountId): ?Tag
    {
        // First try to find by youtube_account property
        $account = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder()
            ->field('properties.youtube_account')->equals($accountId)
            ->getQuery()
            ->getSingleResult();

        if ($account) {
            return $account;
        }

        // Try by login (account name)
        $account = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder()
            ->field('properties.login')->equals($accountId)
            ->getQuery()
            ->getSingleResult();

        if ($account) {
            return $account;
        }

        // Try by Tag ID directly
        try {
            $account = $this->documentManager->getRepository(Tag::class)
                ->createQueryBuilder()
                ->field('_id')->equals($accountId)
                ->field('cod')->regex(new \MongoDB\BSON\Regex('^YOUTUBE_ACCOUNT_'))
                ->getQuery()
                ->getSingleResult();

            if ($account) {
                return $account;
            }
        } catch (\Exception $e) {
            // Invalid ID format, continue
        }

        return null;
    }
}
