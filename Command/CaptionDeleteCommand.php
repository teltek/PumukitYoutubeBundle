<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class CaptionDeleteCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $mmobjRepo = null;

    private $logger;
    private $captionService;
    private $allowedCaptionMimeTypes;
    private $syncStatus;

    private $okDelete = array();
    private $failedDelete = array();
    private $errors = array();

    private $input;
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $youtubeMultimediaObjects = $this->getYoutubeMultimediaObjects();
        $this->deleteCaptionsFromYoutube($youtubeMultimediaObjects);
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
        $this->okDelete = array();
        $this->failedDelete = array();
        $this->errors = array();
        $this->input = $input;
        $this->output = $output;
    }

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
                        $this->logger->addError($errorLog);
                        $this->output->writeln($errorLog);
                        $this->failedDelete[] = $multimediaObject;
                        $this->errors[] = $errorLog;
                        continue;
                    }
                    $this->okDelete[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = sprintf('%s [%s] The deletion of the caption from Youtube of MultimediaObject with id %s failed: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $e->getMessage());
                $this->logger->addError($errorLog);
                $this->output->writeln($errorLog);
                $this->failedDelete[] = $multimediaObject;
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

    private function getMmMaterialIds(MultimediaObject $multimediaObject)
    {
        $materialIds = array();
        foreach ($multimediaObject->getMaterials() as $material) {
            $materialIds[] = $material->getId();
        }

        return $materialIds;
    }

    private function getDeleteCaptionIds(Youtube $youtube, MultimediaObject $multimediaObject)
    {
        $materialIds = $this->getMmMaterialIds($multimediaObject);
        $deleteCaptionIds = array();
        foreach ($youtube->getCaptions() as $caption) {
            $material = $multimediaObject->getMaterialById($caption->getMaterialId());
            if (in_array($caption->getMaterialId(), $materialIds) && !$material->isHide()) {
                continue;
            }
            $deleteCaptionIds[] = $caption->getCaptionId();
            $infoLog = sprintf('%s [%s] Started deleting caption from Youtube with id %s and Caption with id %s', __CLASS__, __FUNCTION__, $youtube->getId(), $caption->getId());
            $this->logger->addInfo($infoLog);
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
