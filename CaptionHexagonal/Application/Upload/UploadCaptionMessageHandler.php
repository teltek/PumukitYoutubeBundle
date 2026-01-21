<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Upload;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\QueueDrainService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class UploadCaptionMessageHandler
{
    private UploadCaptionService $uploadCaptionService;
    private QuotaService $quotaService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        UploadCaptionService $uploadCaptionService,
        QuotaService $quotaService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->uploadCaptionService = $uploadCaptionService;
        $this->quotaService = $quotaService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(UploadCaptionMessage $message): void
    {
        $this->logger->info('[CaptionHexagonal] Processing UploadCaptionMessage', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'language' => $message->getLanguage(),
        ]);

        try {
            $accountId = 'default'; // TODO: Get from MultimediaObject

            $this->quotaService->checkQuotaAvailability($accountId, 450);

            $request = new UploadCaptionRequest(
                $message->getMultimediaObjectId(),
                $message->getLanguage()
            );

            $response = $this->uploadCaptionService->__invoke($request);

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
                    'caption.upload',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'language' => $message->getLanguage(),
                    ],
                    [
                        'language' => $response->getLanguage(),
                        'success' => true,
                    ],
                    true,
                    null,
                    null,
                    200
                );
            }

            $this->quotaService->consumeQuota($accountId, 450, 'caption.upload');

            $this->logger->info('[CaptionHexagonal] Caption uploaded successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'language' => $response->getLanguage(),
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
                    'caption.upload',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'language' => $message->getLanguage(),
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
                    'caption.upload',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'language' => $message->getLanguage(),
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

            $this->logger->error('[CaptionHexagonal] Failed to upload caption', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
