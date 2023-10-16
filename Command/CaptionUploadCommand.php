<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\CaptionsInsertService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CaptionUploadCommand extends Command
{
    private $output;

    private $documentManager;
    private $configurationService;

    private $captionsInsertService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $configurationService,
        CaptionsInsertService $captionsInsertService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->configurationService = $configurationService;
        $this->captionsInsertService = $captionsInsertService;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
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
        $this->output = $output;
        $youtubeMultimediaObjects = $this->getYoutubeMultimediaObjects();
        $infoLog = '[YouTube] Upload captions for '.(is_countable($youtubeMultimediaObjects) ? count($youtubeMultimediaObjects) : 0).' videos.';
        $this->logger->info($infoLog);
        $this->uploadCaptionsToYoutube($youtubeMultimediaObjects, $output);

        return 0;
    }

    private function uploadCaptionsToYoutube($multimediaObjects, OutputInterface $output): void
    {
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $youtube = $this->getYoutubeDocument($multimediaObject);
                if (!$youtube instanceof Youtube) {
                    continue;
                }

                $captionMaterialIds = $this->getCaptionsMaterialIds($youtube);
                $newMaterialIds = $this->getNewMaterialIds($multimediaObject, $captionMaterialIds);

                if (!$newMaterialIds) {
                    continue;
                }

                $result = $this->captionsInsertService->uploadCaption($youtube, $multimediaObject, $newMaterialIds);
            } catch (\Exception $exception) {
                $errorLog = sprintf(
                    '[YouTube] Upload caption of video %s failed. Error: %s',
                    $multimediaObject->getId(),
                    $exception->getMessage()
                );
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
            }
        }
    }

    private function getYoutubeMultimediaObjects()
    {
        $pubChannelTags = $this->configurationService->publicationChannelsTags();
        $queryBuilder = $this->createYoutubeMultimediaObjectsQueryBuilder($pubChannelTags);

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
            || (!in_array($material->getMimeType(), $this->configurationService->allowedCaptionMimeTypes())) || $material->isHide()) {
                continue;
            }
            $newMaterialIds[] = $material->getId();
            $infoLog = sprintf('%s [%s] Started uploading captions to Youtube of MultimediaObject with id %s and Material with id %s', self::class, __FUNCTION__, $multimediaObject->getId(), $material->getId());
            $this->logger->info($infoLog);
            $this->output->writeln($infoLog);
        }

        return $newMaterialIds;
    }

    private function getYoutubeDocument(MultimediaObject $multimediaObject)
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
            'status' => Youtube::STATUS_PUBLISHED,
        ]);
    }

    private function createYoutubeMultimediaObjectsQueryBuilder(array $pubChannelTags)
    {
        if ($this->configurationService->syncStatus()) {
            $aStatus = [MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN];
        } else {
            $aStatus = [MultimediaObject::STATUS_PUBLISHED];
        }

        return $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('properties.pumukit1id')->exists(false)
            ->field('properties.origin')->notEqual('youtube')
            ->field('status')->in($aStatus)
            ->field('embeddedBroadcast.type')->equals('public')
            ->field('tags.cod')->all($pubChannelTags)
        ;
    }
}
