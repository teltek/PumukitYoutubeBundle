<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class CaptionUploadCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $mmobjRepo = null;

    private $logger;
    private $captionService;
    private $allowedCaptionMimeTypes;
    private $syncStatus;

    private $okUploads = array();
    private $failedUploads = array();
    private $errors = array();

    private $input;
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
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $youtubeMultimediaObjects = $this->getYoutubeMultimediaObjects();
        $this->uploadCaptionsToYoutube($youtubeMultimediaObjects, $output);
        $this->checkResultsAndSendEmail();
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $this->dm = $container->get('doctrine_mongodb')->getManager();
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->captionService = $container->get('pumukityoutube.caption');
        $this->logger = $container->get('monolog.logger.youtube');
        $this->syncStatus = $container->getParameter('pumukit_youtube.sync_status');
        $this->allowedCaptionMimeTypes = $container->getParameter('pumukit_youtube.allowed_caption_mimetypes');
        $this->okUploads = array();
        $this->failedUploads = array();
        $this->errors = array();
        $this->input = $input;
        $this->output = $output;
    }

    private function uploadCaptionsToYoutube($mms, OutputInterface $output)
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
                        $this->logger->addError($errorLog);
                        $this->output->writeln($errorLog);
                        $this->failedUploads[] = $multimediaObject;
                        $this->errors[] = $errorLog;
                        continue;
                    }
                    $this->okUploads[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = sprintf('%s [%s] The upload of the caption from the Multimedia Object with id %s failed: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $e->getMessage());
                $this->logger->addError($errorLog);
                $this->output->writeln($errorLog);
                $this->failedUploads[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function createYoutubeMultimediaObjectsQueryBuilder()
    {
        $array_pub_tags = $this->getContainer()->getParameter('pumukit_youtube.pub_channels_tags');

        $syncStatus = $this->getContainer()->getParameter('pumukit_youtube.sync_status');
        if ($syncStatus) {
            $aStatus = array(MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN);
        } else {
            $aStatus = array(MultimediaObject::STATUS_PUBLISHED);
        }

        return $this->mmobjRepo->createQueryBuilder()
          ->field('properties.pumukit1id')->exists(false)
          ->field('properties.origin')->notEqual('youtube')
          ->field('status')->in($aStatus)
          ->field('embeddedBroadcast.type')->equals('public')
          ->field('tags.cod')->all($array_pub_tags);
    }

    private function getYoutubeMultimediaObjects()
    {
        return $this->createYoutubeMultimediaObjectsQueryBuilder()
          ->field('properties.youtube')->exists(true)
          ->getQuery()
          ->execute();
    }

    private function getCaptionsMaterialIds(Youtube $youtube)
    {
        $captions = $youtube->getCaptions();
        $captionsMaterialIds = array();
        foreach ($captions as $caption) {
            $captionsMaterialIds[] = $caption->getMaterialId();
        }

        return $captionsMaterialIds;
    }

    private function getNewMaterialIds(MultimediaObject $multimediaObject, array $captionMaterialIds = array())
    {
        $newMaterialIds = array();
        foreach ($multimediaObject->getMaterials() as $material) {
            if ((in_array($material->getId(), $captionMaterialIds)) ||
            (!in_array($material->getMimeType(), $this->allowedCaptionMimeTypes)) || $material->isHide()) {
                continue;
            }
            $newMaterialIds[] = $material->getId();
            $infoLog = sprintf('%s [%s] Started uploading captions to Youtube of MultimediaObject with id %s and Material with id %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $material->getId());
            $this->logger->addInfo($infoLog);
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
