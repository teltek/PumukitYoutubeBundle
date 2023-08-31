<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\CaptionsDataValidationService;
use Pumukit\YoutubeBundle\Services\CaptionsDeleteService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CaptionDeleteCommand extends Command
{
    private $output;

    private $documentManager;
    private $configurationService;

    private $captionsDataValidationService;
    private $captionsDeleteService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $configurationService,
        CaptionsDeleteService $captionsDeleteService,
        CaptionsDataValidationService $captionsDataValidationService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->configurationService = $configurationService;
        $this->captionsDeleteService = $captionsDeleteService;
        $this->captionsDataValidationService = $captionsDataValidationService;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:caption:delete')
            ->setDescription('Delete captions from Youtube')
            ->setHelp(
                <<<'EOT'
Command to delete a controlled set of captions from Youtube.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $youtubeMultimediaObjects = $this->getYoutubeMultimediaObjects();

        $infoLog = '[YouTube] Deleting captions for '.count($youtubeMultimediaObjects).' videos.';
        $this->logger->info($infoLog);
        $this->deleteCaptionsFromYoutube($youtubeMultimediaObjects);

        return 0;
    }

    private function deleteCaptionsFromYoutube($multimediaObjects): void
    {
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $youtube = $this->getYoutubeDocument($multimediaObject);
                if (!$youtube instanceof Youtube) {
                    continue;
                }
                $deleteCaptionIds = $this->getDeleteCaptionIds($youtube, $multimediaObject);
                if ($deleteCaptionIds) {
                    $account = $this->captionsDataValidationService->validateMultimediaObjectAccount($multimediaObject);
                    $result = $this->captionsDeleteService->deleteCaption($account, $youtube, $deleteCaptionIds);
                }
            } catch (\Exception $exception) {
                $errorLog = sprintf('[YouTube] Remove captions for video %s failed. Error: %s', $multimediaObject->getId(), $exception->getMessage());
                $this->logger->error($errorLog);
                $this->output->writeln($errorLog);
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

    private function getMmMaterialIds(MultimediaObject $multimediaObject): array
    {
        $materialIds = [];
        foreach ($multimediaObject->getMaterials() as $material) {
            $materialIds[] = $material->getId();
        }

        return $materialIds;
    }

    private function getDeleteCaptionIds(Youtube $youtube, MultimediaObject $multimediaObject): array
    {
        $materialIds = $this->getMmMaterialIds($multimediaObject);
        $deleteCaptionIds = [];
        $youtubeCaptions = $youtube->getCaptions();

        foreach ($youtubeCaptions as $caption) {
            $material = $multimediaObject->getMaterialById($caption->getMaterialId());
            if (in_array($caption->getMaterialId(), $materialIds) && !$material->isHide()) {
                continue;
            }
            $deleteCaptionIds[] = $caption->getCaptionId();
            $infoLog = sprintf('%s [%s] Started deleting caption from Youtube with id %s and Caption with id %s', __CLASS__, __FUNCTION__, $youtube->getId(), $caption->getId());
            $this->logger->info($infoLog);
            $this->output->writeln($infoLog);
        }

        return $deleteCaptionIds;
    }

    private function getYoutubeDocument(MultimediaObject $multimediaObject)
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
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
