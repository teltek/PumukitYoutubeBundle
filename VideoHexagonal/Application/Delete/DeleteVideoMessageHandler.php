<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Delete;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\QueueDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class DeleteVideoMessageHandler
{
    private DeleteVideoService $deleteVideoService;
    private QuotaService $quotaService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        DeleteVideoService $deleteVideoService,
        QuotaService $quotaService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->deleteVideoService = $deleteVideoService;
        $this->quotaService = $quotaService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(DeleteVideoMessage $message): void
    {
        $this->logger->info('[VideoHexagonal] Processing DeleteVideoMessage', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        try {
            $accountId = 'default'; // TODO: Get from MultimediaObject

            // 1. Check quota (50 cost for delete)
            $this->quotaService->checkQuotaAvailability($accountId, 50);

            // 2. Execute
            $request = new DeleteVideoRequest($message->getMultimediaObjectId());
            $response = $this->deleteVideoService->__invoke($request);

            // 3. Log API Response
            $account = $this->findAccountTag($accountId);

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'video.delete',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
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

            // 4. Consume quota
            $this->quotaService->consumeQuota($accountId, 50, 'video.delete');

            $this->logger->info('[VideoHexagonal] Video deleted successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
        } catch (\Google_Service_Exception $e) {
            // Log API error response
            $account = $this->findAccountTag($accountId ?? 'default');

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'video.delete',
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
                    'video.delete',
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

            $this->logger->error('[VideoHexagonal] Failed to delete video', [
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
