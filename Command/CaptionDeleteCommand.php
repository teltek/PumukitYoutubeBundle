<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Repository\MultimediaObjectRepository;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\CaptionService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CaptionDeleteCommand extends ContainerAwareCommand
{
    /**
     * @var DocumentManager
     */
    private $dm;
    /**
     * @var MultimediaObjectRepository
     */
    private $mmobjRepo;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var CaptionService
     */
    private $captionService;
    private $allowedCaptionMimeTypes;
    private $syncStatus;

    private $okDelete = [];
    private $failedDelete = [];
    private $errors = [];
    /**
     * @var InputInterface
     */
    private $input;
    /**
     * @var OutputInterface
     */
    private $output;

    protected function configure()
    {
        $this
            ->setName('youtube:caption:delete')
            ->setDescription('Delete captions from Youtube')
            ->setHelp(
                <<<'EOT'
Command to delete a controlled set of captions from Youtube.

EOT
            )
        ;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return null|int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $youtubeMultimediaObjects = $this->getYoutubeMultimediaObjects();
        $this->deleteCaptionsFromYoutube($youtubeMultimediaObjects);
        $this->checkResultsAndSendEmail();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->mmobjRepo = $this->dm->getRepository(MultimediaObject::class);
        $this->captionService = $this->getContainer()->get('pumukityoutube.caption');
        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
        $this->syncStatus = $this->getContainer()->getParameter('pumukit_youtube.sync_status');
        $this->allowedCaptionMimeTypes = $this->getContainer()->getParameter('pumukit_youtube.allowed_caption_mimetypes');
        $this->okDelete = [];
        $this->failedDelete = [];
        $this->errors = [];
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * @param array $mms
     */
    private function deleteCaptionsFromYoutube($mms)
    {
        foreach ($mms as $multimediaObject) {
            try {
                $youtube = $this->captionService->getYoutubeDocument($multimediaObject);
                if (null == $youtube) {
                    continue;
                }
                $deleteCaptionIds = $this->getDeleteCaptionIds($youtube, $multimediaObject);
                if ($deleteCaptionIds) {
                    $outDelete = $this->captionService->deleteCaption($multimediaObject, $deleteCaptionIds);
                    if (0 !== $outDelete) {
                        $errorLog = sprintf('%s [%s] Unknown error in deleting caption from Youtube of MultimediaObject with id %s: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $outDelete);
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

    /**
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    private function getYoutubeMultimediaObjects()
    {
        $pubChannelTags = $this->getContainer()->getParameter('pumukit_youtube.pub_channels_tags');
        $queryBuilder = $this->captionService->createYoutubeMultimediaObjectsQueryBuilder($pubChannelTags);

        return $queryBuilder
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * @param MultimediaObject $multimediaObject
     *
     * @return array
     */
    private function getMmMaterialIds(MultimediaObject $multimediaObject)
    {
        $materialIds = [];
        foreach ($multimediaObject->getMaterials() as $material) {
            $materialIds[] = $material->getId();
        }

        return $materialIds;
    }

    /**
     * @param Youtube          $youtube
     * @param MultimediaObject $multimediaObject
     *
     * @return array
     */
    private function getDeleteCaptionIds(Youtube $youtube, MultimediaObject $multimediaObject)
    {
        $materialIds = $this->getMmMaterialIds($multimediaObject);
        $deleteCaptionIds = [];
        foreach ($youtube->getCaptions() as $caption) {
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

    private function checkResultsAndSendEmail()
    {
        if (!empty($this->failedDelete)) {
            $this->captionService->sendEmail('caption delete', $this->okDelete, $this->failedDelete, $this->errors);
        }
    }
}
