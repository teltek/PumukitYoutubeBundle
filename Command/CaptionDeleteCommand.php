<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\Services\CaptionsDeleteService;
use Pumukit\YoutubeBundle\Services\CaptionService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CaptionDeleteCommand extends Command
{
    private $mmobjRepo;
    private $allowedCaptionMimeTypes;
    private $syncStatus;
    private $okDelete = [];
    private $failedDelete = [];
    private $errors = [];
    private $input;
    private $output;

    private $documentManager;
    private $configurationService;
    private $captionsDeleteService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $configurationService,
        CaptionsDeleteService $captionsDeleteService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->configurationService = $configurationService;

        $this->captionsDeleteService = $captionsDeleteService;
        $this->logger = $logger;
        parent::__construct();
    }

    public function validateMultimediaObjectAccount(MultimediaObject $multimediaObject): ?Tag
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);
        $account = null;
        foreach ($multimediaObject->getTags() as $tag) {
            if ($tag->isChildOf($youtubeTag)) {
                $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $tag->getCod()]);

                break;
            }
        }

        return $account;
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
        $youtubeMultimediaObjects = $this->getYoutubeMultimediaObjects();
        $this->deleteCaptionsFromYoutube($youtubeMultimediaObjects);
        // $this->checkResultsAndSendEmail();

        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->mmobjRepo = $this->documentManager->getRepository(MultimediaObject::class);
        $this->allowedCaptionMimeTypes = $this->configurationService->allowedCaptionMimeTypes();
        $this->okDelete = [];
        $this->failedDelete = [];
        $this->errors = [];
        $this->input = $input;
        $this->output = $output;
    }

    private function deleteCaptionsFromYoutube($multimediaObjects)
    {
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $youtube = $this->getYoutubeDocument($multimediaObject);
                if (!$youtube instanceof Youtube) {
                    continue;
                }
                $deleteCaptionIds = $this->getDeleteCaptionIds($youtube, $multimediaObject);
                if ($deleteCaptionIds) {
                    $account = $this->validateMultimediaObjectAccount($multimediaObject);
                    $result = $this->captionsDeleteService->deleteCaption($account, $youtube, $deleteCaptionIds);
                    /*if (0 !== $outDelete) {
                        $errorLog = sprintf('%s [%s] Unknown error in deleting caption from Youtube of MultimediaObject with id %s: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $outDelete);
                        $this->logger->error($errorLog);
                        $this->output->writeln($errorLog);
                        $this->failedDelete[] = $multimediaObject;
                        $this->errors[] = $errorLog;

                        continue;
                    }*/
                    if (!$result) {
                        // $errorLog = sprintf('%s [%s] Unknown error in deleting caption from Youtube of MultimediaObject with id %s: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $outDelete);
                        $errorLog = 'error';
                        $this->logger->error($errorLog);
                        $this->output->writeln($errorLog);
                        $this->failedDelete[] = $multimediaObject;
                        $this->errors[] = $errorLog;

                        continue;
                    }
                    $this->okDelete[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = sprintf('%s [%s] The deletion of the caption from Youtube of MultimediaObject with id %s failed: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $e->getMessage());
                $this->logger->error($errorLog);
                $this->output->writeln($errorLog);
                $this->failedDelete[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
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

/*    private function checkResultsAndSendEmail(): void
    {
        if (!empty($this->failedDelete)) {
            $this->captionService->sendEmail('caption delete', $this->okDelete, $this->failedDelete, $this->errors);
        }
    }*/
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
            ->field('tags.cod')->all($pubChannelTags);
    }
}
