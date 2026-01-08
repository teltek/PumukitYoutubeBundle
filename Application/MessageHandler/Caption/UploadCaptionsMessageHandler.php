<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Caption;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Caption\UploadCaptionsMessage;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Psr\Log\LoggerInterface;

final class UploadCaptionsMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly QuotaService $quotaService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UploadCaptionsMessage $message): void
    {
        $this->logger->info('[UploadCaptionsMessageHandler] Processing caption upload', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'videoId' => $message->getVideoId(),
            'accountId' => $message->getAccountId(),
            'language' => $message->getLanguage(),
            'captionFile' => $message->getCaptionFile(),
        ]);

        try {
            // Verify caption file exists
            if (!file_exists($message->getCaptionFile())) {
                throw new \RuntimeException('Caption file not found: ' . $message->getCaptionFile());
            }

            // Load account
            $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
            $account = $accountRepo->find($message->getAccountId());

            if (!$account) {
                throw new \RuntimeException('Account not found: ' . $message->getAccountId());
            }

            // Check quota (captions.insert = 400 units - very expensive!)
            $quotaResult = $this->quotaService->checkQuota($account, 'captions.insert');
            if (!$quotaResult['canProceed']) {
                $this->logger->warning('[UploadCaptionsMessageHandler] Insufficient quota', [
                    'accountId' => $message->getAccountId(),
                    'available' => $quotaResult['available'],
                    'required' => $quotaResult['required'],
                ]);
                throw new \RuntimeException('Insufficient quota to upload captions');
            }

            // Create Google API client
            $client = $this->googleClientFactory->createClient($account);
            $youtubeService = new \Google_Service_YouTube($client);

            // Create caption snippet
            $captionSnippet = new \Google_Service_YouTube_CaptionSnippet();
            $captionSnippet->setVideoId($message->getVideoId());
            $captionSnippet->setLanguage($message->getLanguage());
            $captionSnippet->setName('Captions - ' . $message->getLanguage());
            $captionSnippet->setIsDraft(false);

            // Create caption resource
            $caption = new \Google_Service_YouTube_Caption();
            $caption->setSnippet($captionSnippet);

            // Upload caption file
            $client->setDefer(true);
            $request = $youtubeService->captions->insert('snippet', $caption, [
                'data' => file_get_contents($message->getCaptionFile()),
                'mimeType' => $this->getMimeType($message->getCaptionFile()),
                'uploadType' => 'multipart'
            ]);

            $response = $client->execute($request);
            $client->setDefer(false);

            $this->logger->info('[UploadCaptionsMessageHandler] Caption uploaded successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'videoId' => $message->getVideoId(),
                'language' => $message->getLanguage(),
                'captionId' => $response->getId(),
            ]);

            // Consume quota (400 units - very expensive!)
            $this->quotaService->consumeQuota(
                account: $account,
                operation: 'captions.insert',
                metadata: [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'videoId' => $message->getVideoId(),
                    'language' => $message->getLanguage(),
                    'captionId' => $response->getId(),
                    'captionFile' => basename($message->getCaptionFile()),
                    'response' => json_decode(json_encode($response), true)
                ]
            );

            $this->logger->info('[UploadCaptionsMessageHandler] Quota consumed successfully', [
                'accountId' => $message->getAccountId(),
                'quotaUsed' => 400,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[UploadCaptionsMessageHandler] Error uploading captions', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'videoId' => $message->getVideoId(),
                'accountId' => $message->getAccountId(),
                'language' => $message->getLanguage(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return match ($extension) {
            'srt' => 'application/x-subrip',
            'vtt' => 'text/vtt',
            'sbv' => 'text/plain',
            'sub' => 'text/plain',
            default => 'text/plain',
        };
    }
}
