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

class CaptionUploadCommand extends ContainerAwareCommand
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

    private $okUploads = [];
    private $failedUploads = [];
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
            ->setName('youtube:caption:upload')
            ->setDescription('Upload captions from Multimedia Objects Materials to Youtube')
            ->setHelp(
                <<<'EOT'
Command to upload a controlled set of captions to Youtube.

EOT
          )
        ;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return null|int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $youtubeMultimediaObjects = $this->getYoutubeMultimediaObjects();
        $this->uploadCaptionsToYoutube($youtubeMultimediaObjects);
        $this->checkResultsAndSendEmail();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $this->dm = $container->get('doctrine_mongodb')->getManager();
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->captionService = $container->get('pumukityoutube.caption');
        $this->logger = $container->get('monolog.logger.youtube');
        $this->syncStatus = $container->getParameter('pumukit_youtube.sync_status');
        $this->allowedCaptionMimeTypes = $container->getParameter('pumukit_youtube.allowed_caption_mimetypes');
        $this->okUploads = [];
        $this->failedUploads = [];
        $this->errors = [];
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * @param array $mms
     */
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
     * @param Youtube $youtube
     *
     * @return array
     */
    private function getCaptionsMaterialIds(Youtube $youtube)
    {
        $captions = $youtube->getCaptions();
        $captionsMaterialIds = [];
        foreach ($captions as $caption) {
            $captionsMaterialIds[] = $caption->getMaterialId();
        }

        return $captionsMaterialIds;
    }

    /**
     * @param MultimediaObject $multimediaObject
     * @param array            $captionMaterialIds
     *
     * @return array
     */
    private function getNewMaterialIds(MultimediaObject $multimediaObject, array $captionMaterialIds = [])
    {
        $newMaterialIds = [];
        foreach ($multimediaObject->getMaterials() as $material) {
            if ((in_array($material->getId(), $captionMaterialIds)) ||
            (!in_array($material->getMimeType(), $this->allowedCaptionMimeTypes)) || $material->isHide()) {
                continue;
            }
            $newMaterialIds[] = $material->getId();
            $infoLog = sprintf('%s [%s] Started uploading captions to Youtube of MultimediaObject with id %s and Material with id %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $material->getId());
            $this->logger->info($infoLog);
            $this->output->writeln($infoLog);
        }

        return $newMaterialIds;
    }

    private function checkResultsAndSendEmail()
    {
        if (!empty($this->failedUploads)) {
            $this->captionService->sendEmail('caption upload', $this->okUploads, $this->failedUploads, $this->errors);
        }
    }

}
