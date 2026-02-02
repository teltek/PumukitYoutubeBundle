<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Delete;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\QueueDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class DeleteCaptionMessageHandler
{
    private DeleteCaptionService $deleteCaptionService;
    private QuotaService $quotaService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        DeleteCaptionService $deleteCaptionService,
        QuotaService $quotaService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->deleteCaptionService = $deleteCaptionService;
        $this->quotaService = $quotaService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(DeleteCaptionMessage $message): void
    {
        $this->logger->info('[CaptionHexagonal] Processing DeleteCaptionMessage', [
            'youtubeId' => $message->getYoutubeId(),
            'captionId' => $message->getCaptionId(),
        ]);

        try {
            $accountId = 'default'; // TODO: Get from video

            $this->quotaService->checkQuotaAvailability($accountId, 50);

            $request = new DeleteCaptionRequest(
                $message->getYoutubeId(),
                $message->getCaptionId()
            );

            $response = $this->deleteCaptionService->__invoke($request);

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
                    'caption.delete',
                    [
                        'youtubeId' => $message->getYoutubeId(),
                        'captionId' => $message->getCaptionId(),
                    ],
                    [
                        'captionId' => $response->getCaptionId(),
                        'success' => true,
                    ],
                    true,
                    null,
                    null,
                    200
                );
            }

            $this->quotaService->consumeQuota($accountId, 50, 'caption.delete');

            $this->logger->info('[CaptionHexagonal] Caption deleted successfully', [
                'captionId' => $response->getCaptionId(),
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
                    'caption.delete',
                    [
                        'youtubeId' => $message->getYoutubeId(),
                        'captionId' => $message->getCaptionId(),
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
                $this->logger->warning('[CaptionHexagonal] Quota exceeded');
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
                    'caption.delete',
                    [
                        'youtubeId' => $message->getYoutubeId(),
                        'captionId' => $message->getCaptionId(),
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

            $this->logger->error('[CaptionHexagonal] Failed to delete caption', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
