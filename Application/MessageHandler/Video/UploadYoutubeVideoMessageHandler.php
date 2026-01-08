<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Video;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeUploadConfig;
use Pumukit\YoutubeBundle\Domain\Service\AccountResolver;
use Pumukit\YoutubeBundle\Domain\Service\MultimediaObjectValidator;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Pumukit\YoutubeBundle\Application\Message\Video\UploadYoutubeVideoMessage;
use Psr\Log\LoggerInterface;

class UploadYoutubeVideoMessageHandler
{
    public function __construct(
        private readonly YoutubeEventService $youtubeEventService,
        private readonly MultimediaObjectValidator $multimediaObjectValidator,
        private readonly AccountResolver $accountResolver,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UploadYoutubeVideoMessage $message): void
    {
        $this->logger->info('[UploadYoutubeVideoMessageHandler] Processing upload message', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'accountName' => $message->getAccountName(),
            'forceReupload' => $message->isForceReupload(),
        ]);

        try {
            // 1. Load MultimediaObject
            $multimediaObject = $this->documentManager
                ->getRepository(MultimediaObject::class)
                ->find(new ObjectId($message->getMultimediaObjectId()));

            if (!$multimediaObject) {
                $this->logger->error('[UploadYoutubeVideoMessageHandler] MultimediaObject not found', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 2. Check if already uploaded (unless force reupload)
            if (!$message->isForceReupload()) {
                $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
                if ($youtubeVideoId) {
                    $this->logger->info('[UploadYoutubeVideoMessageHandler] Video already uploaded, skipping', [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'youtubeVideoId' => $youtubeVideoId,
                    ]);
                    return;
                }
            }

            // 3. Validate MultimediaObject
            if (!$this->validator->isValidForYoutubeUpload($multimediaObject)) {
                $this->logger->warning('[UploadYoutubeVideoMessageHandler] MultimediaObject not valid for upload', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 4. Resolve YouTube Account
            $accountId = $message->getAccountName()
                ? $this->getAccountIdByName($message->getAccountName())
                : $this->accountResolver->resolveAccount($multimediaObject);

            if (!$accountId) {
                $this->logger->error('[UploadYoutubeVideoMessageHandler] Could not resolve YouTube account', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'accountName' => $message->getAccountName(),
                ]);
                return;
            }

            // 5. Resolve Playlists
            $playlists = $this->accountResolver->resolvePlaylists($multimediaObject);

            // 6. Create or Update YoutubeUploadConfig
            $config = $this->ensureUploadConfig(
                $message->getMultimediaObjectId(),
                $accountId,
                $playlists
            );

            if (!$config) {
                $this->logger->error('[UploadYoutubeVideoMessageHandler] Failed to create upload config', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 7. Trigger the actual upload via YoutubeEventService
            $this->logger->info('[UploadYoutubeVideoMessageHandler] Triggering YouTube upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'accountId' => $accountId,
                'configId' => $config->getId(),
                'playlists' => $playlists,
            ]);

            $context = [
                'triggered_by' => 'backoffice_button',
                'config_id' => $config->getId(),
                'youtube_account_id' => $accountId,
                'playlists' => $playlists,
                'credentials_path' => null, // Will be resolved by the service
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];

            $this->youtubeEventService->handleUploadEvent(
                $message->getMultimediaObjectId(),
                $context
            );

            $this->logger->info('[UploadYoutubeVideoMessageHandler] Upload completed successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
        } catch (\RuntimeException $e) {
            // Log and re-throw RuntimeExceptions
            $this->logger->error('[UploadYoutubeVideoMessageHandler] Error processing upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('[UploadYoutubeVideoMessageHandler] Error processing upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism if configured
            throw $e;
        }
    }

    /**
     * Get account ID by account name.
     */
    private function getAccountIdByName(string $accountName): ?string
    {
        $account = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->findOneBy(['name' => $accountName]);

        return $account?->getId();
    }

    /**
     * Creates or updates YoutubeUploadConfig for the MultimediaObject.
     */
    private function ensureUploadConfig(
        string $multimediaObjectId,
        string $youtubeAccountId,
        array $playlists
    ): ?YoutubeUploadConfig {
        // Check if config already exists
        $existingConfig = $this->documentManager
            ->getRepository(YoutubeUploadConfig::class)
            ->findOneBy(['multimediaObjectId' => $multimediaObjectId]);

        if ($existingConfig) {
            // Update existing config
            $existingConfig->changeYoutubeAccount($youtubeAccountId);
            $existingConfig->updatePlaylists($playlists);

            $this->documentManager->flush();

            $this->logger->debug('[UploadYoutubeVideoMessageHandler] Updated existing config', [
                'configId' => $existingConfig->getId(),
                'multimediaObjectId' => $multimediaObjectId,
            ]);

            return $existingConfig;
        }

        // Create new config
        $config = YoutubeUploadConfig::create(
            $multimediaObjectId,
            $youtubeAccountId,
            $playlists
        );

        $this->documentManager->persist($config);
        $this->documentManager->flush();

        $this->logger->debug('[UploadYoutubeVideoMessageHandler] Created new config', [
            'configId' => $config->getId(),
            'multimediaObjectId' => $multimediaObjectId,
        ]);

        return $config;
    }
}
