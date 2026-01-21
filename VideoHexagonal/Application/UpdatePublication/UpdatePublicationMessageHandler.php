<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\UpdatePublication;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\QueueDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class UpdatePublicationMessageHandler
{
    private UpdatePublicationService $updatePublicationService;
    private QuotaService $quotaService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        UpdatePublicationService $updatePublicationService,
        QuotaService $quotaService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->updatePublicationService = $updatePublicationService;
        $this->quotaService = $quotaService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(UpdatePublicationMessage $message): void
    {
        $this->logger->info('[VideoHexagonal] Processing UpdatePublicationMessage', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'privacy' => $message->getPrivacy(),
        ]);

        try {
            $accountId = 'default'; // TODO: Get from MultimediaObject

            $this->quotaService->checkQuotaAvailability($accountId, 50);

            $request = new UpdatePublicationRequest(
                $message->getMultimediaObjectId(),
                $message->getPrivacy()
            );

            $response = $this->updatePublicationService->__invoke($request);

            // Log API Response
            $account = $this->findAccountTag($accountId);

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'video.updatePublication',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'privacy' => $message->getPrivacy(),
                    ],
                    [
                        'success' => true,
                        'privacy' => $response->getPrivacy(),
                    ],
                    true,
                    null,
                    null,
                    200
                );
            }

            $this->quotaService->consumeQuota($accountId, 50, 'video.updatePublication');

            $this->logger->info('[VideoHexagonal] Publication updated successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'privacy' => $response->getPrivacy(),
            ]);
        } catch (\Google_Service_Exception $e) {
            // Log API error response
            $account = $this->findAccountTag($accountId ?? 'default');

            if ($account) {
                $this->quotaService->logApiResponse(
                    $account,
                    'video.updatePublication',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
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
                    'video.updatePublication',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
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

            $this->logger->error('[VideoHexagonal] Failed to update publication', [
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
