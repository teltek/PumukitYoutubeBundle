<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\CaptionService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CaptionUploadCommand extends Command
{
    private $mmobjRepo;
    private $allowedCaptionMimeTypes;
    private $syncStatus;
    private $okUploads = [];
    private $failedUploads = [];
    private $errors = [];
    private $input;
    private $output;

    private $documentManager;
    private $configurationService;
    private $captionService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $configurationService,
        CaptionService $captionService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->configurationService = $configurationService;
        $this->captionService = $captionService;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:caption:upload')
            ->setDescription('Upload captions from Multimedia Objects Materials to Youtube')
            ->setHelp(
                <<<'EOT'
Command to upload a controlled set of captions to Youtube.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $youtubeMultimediaObjects = $this->getYoutubeMultimediaObjects();
        $this->uploadCaptionsToYoutube($youtubeMultimediaObjects);
        $this->checkResultsAndSendEmail();

        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->mmobjRepo = $this->documentManager->getRepository(MultimediaObject::class);
        $this->syncStatus = $this->configurationService->syncStatus();
        $this->allowedCaptionMimeTypes = $this->configurationService->allowedCaptionMimeTypes();
        $this->okUploads = [];
        $this->failedUploads = [];
        $this->errors = [];
        $this->input = $input;
        $this->output = $output;
    }

    private function uploadCaptionsToYoutube(array $mms)
    {
        foreach ($mms as $multimediaObject) {
            try {
                $youtube = $this->captionService->getYoutubeDocument($multimediaObject);
                if (null == $youtube) {
                    continue;
                }
                $captionMaterialIds = $this->getCaptionsMaterialIds($youtube);
                $newMaterialIds = $this->getNewMaterialIds($multimediaObject, $captionMaterialIds);
                if ($newMaterialIds) {
                    $outUpload = $this->captionService->uploadCaption($multimediaObject, $newMaterialIds);
                    if (!is_array($outUpload)) {
                        $errorLog = sprintf('%s [%s] Unknown error in the upload caption to Youtube of MultimediaObject with id %s: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $outUpload);
                        $this->logger->error($errorLog);
                        $this->output->writeln($errorLog);
                        $this->failedUploads[] = $multimediaObject;
                        $this->errors[] = $errorLog;

                        continue;
                    }
                    $this->okUploads[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = sprintf('%s [%s] The upload of the caption from the Multimedia Object with id %s failed: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $e->getMessage());
                $this->logger->error($errorLog);
                $this->output->writeln($errorLog);
                $this->failedUploads[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function getYoutubeMultimediaObjects()
    {
        $pubChannelTags = $this->configurationService->publicationChannelsTags();
        $queryBuilder = $this->captionService->createYoutubeMultimediaObjectsQueryBuilder($pubChannelTags);

        return $queryBuilder
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute()
        ;
    }

    private function getCaptionsMaterialIds(Youtube $youtube): array
    {
        $captions = $youtube->getCaptions();
        $captionsMaterialIds = [];
        foreach ($captions as $caption) {
            $captionsMaterialIds[] = $caption->getMaterialId();
        }

        return $captionsMaterialIds;
    }

    private function getNewMaterialIds(MultimediaObject $multimediaObject, array $captionMaterialIds = []): array
    {
        $newMaterialIds = [];
        foreach ($multimediaObject->getMaterials() as $material) {
            if (in_array($material->getId(), $captionMaterialIds)
            || (!in_array($material->getMimeType(), $this->allowedCaptionMimeTypes)) || $material->isHide()) {
                continue;
            }
            $newMaterialIds[] = $material->getId();
            $infoLog = sprintf('%s [%s] Started uploading captions to Youtube of MultimediaObject with id %s and Material with id %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $material->getId());
            $this->logger->info($infoLog);
            $this->output->writeln($infoLog);
        }

        return $newMaterialIds;
    }

    private function checkResultsAndSendEmail(): void
    {
        if (!empty($this->failedUploads)) {
            $this->captionService->sendEmail('caption upload', $this->okUploads, $this->failedUploads, $this->errors);
        }
    }
}
