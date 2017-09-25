<?php

namespace Pumukit\YoutubeBundle\Command;

use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeUploadCommand extends ContainerAwareCommand
{
    const PUB_CHANNEL_YOUTUBE = 'PUCHYOUTUBE';
    const PUB_DECISION_AUTONOMOUS = 'PUDEAUTO';
    const DEFAULT_PLAYLIST_COD = 'YOUTUBECONFERENCES';
    const DEFAULT_PLAYLIST_TITLE = 'Conferences';
    const METATAG_PLAYLIST_COD = 'YOUTUBE';
    const METATAG_PLAYLIST_PATH = 'ROOT|YOUTUBE|';

    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;

    private $logger;
    private $youtubeService;

    private $okUploads = array();
    private $failedUploads = array();
    private $errors = array();

    protected function configure()
    {
        $this->setName('youtube:upload')->setDescription('Upload videos from Multimedia Objects to Youtube')->setHelp(
                <<<'EOT'
Command to upload a controlled videos to Youtube.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        $newMultimediaObjects = $this->getNewMultimediaObjectsToUpload();
        $this->uploadVideosToYoutube($newMultimediaObjects, $output);

        $errorStatus = array(
            Youtube::STATUS_HTTP_ERROR,
            Youtube::STATUS_ERROR,
            Youtube::STATUS_UPDATE_ERROR,
        );
        $failureMultimediaObjects = $this->getUploadsByStatus($errorStatus);
        $this->uploadVideosToYoutube($failureMultimediaObjects, $output);

        $removedStatus = array(Youtube::STATUS_REMOVED);
        $removedYoutubeMultimediaObjects = $this->getUploadsByStatus($removedStatus);
        $this->uploadVideosToYoutube($removedYoutubeMultimediaObjects, $output);

        $this->checkResultsAndSendEmail();
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $container = $this->getContainer();
        $this->youtubeService = $container->get('pumukityoutube.youtube');
        $this->logger = $container->get('monolog.logger.youtube');

        $this->syncStatus = $container->getParameter('pumukit_youtube.sync_status');

        $this->okUploads = array();
        $this->failedUploads = array();
        $this->errors = array();
    }

    private function uploadVideosToYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                $infoLog = sprintf(
                    '%s [%s] Started uploading to Youtube of MultimediaObject with id %s',
                    __CLASS__,
                    __FUNCTION__,
                    $mm->getId()
                );
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $status = 'public';
                if ($this->syncStatus) {
                    $status = YoutubeService::$status[$mm->getStatus()];
                }
                $outUpload = $this->youtubeService->upload($mm, 27, $status, false);
                if (0 !== $outUpload) {
                    $errorLog = sprintf(
                        '%s [%s] Unknown error in the upload to Youtube of MultimediaObject with id %s: %s',
                        __CLASS__,
                        __FUNCTION__,
                        $mm->getId(),
                        $outUpload
                    );
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUploads[] = $mm;
                    $this->errors[] = $errorLog;
                    continue;
                }
                $this->okUploads[] = $mm;
            } catch (\Exception $e) {
                $errorLog = sprintf(
                    '%s [%s] The upload of the video from the Multimedia Object with id %s failed: %s',
                    __CLASS__,
                    __FUNCTION__,
                    $mm->getId(),
                    $e->getMessage()
                );
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedUploads[] = $mm;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function createMultimediaObjectsToUploadQueryBuilder()
    {
        $array_pub_tags = $this->getContainer()->getParameter('pumukit_youtube.pub_channels_tags');

        $syncStatus = $this->getContainer()->getParameter('pumukit_youtube.sync_status');
        if ($syncStatus) {
            $aStatus = array(
                MultimediaObject::STATUS_PUBLISHED,
                MultimediaObject::STATUS_BLOQ,
                MultimediaObject::STATUS_HIDDEN,
            );
        } else {
            $aStatus = array(MultimediaObject::STATUS_PUBLISHED);
        }

        return $this->mmobjRepo
            ->createQueryBuilder()
            ->field('properties.pumukit1id')
            ->exists(false)
            ->field('properties.origin')
            ->notEqual('youtube')
            ->field('status')
            ->in($aStatus)
            ->field('embeddedBroadcast.type')
            ->equals('public')
            ->field('tags.cod')
            ->all($array_pub_tags);
    }

    private function getNewMultimediaObjectsToUpload()
    {
        return $this->createMultimediaObjectsToUploadQueryBuilder()
            ->field('properties.youtube')
            ->exists(false)
            ->getQuery()
            ->execute();
    }

    private function getUploadsByStatus($statusArray = array())
    {
        $mmIds = $this->youtubeRepo->getDistinctMultimediaObjectIdsWithAnyStatus($statusArray);

        return $this->createMultimediaObjectsToUploadQueryBuilder()
            ->field('_id')
            ->in($mmIds->toArray())
            ->getQuery()
            ->execute();
    }

    private function checkResultsAndSendEmail()
    {
        $youtubeTag = $this->tagRepo->findByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            foreach ($this->okUploads as $mm) {
                if (!$mm->containsTagWithCod(self::PUB_CHANNEL_YOUTUBE)) {
                    $addedTags = $this->tagService->addTagToMultimediaObject($mm, $youtubeTag->getId(), false);
                }
            }
            $this->dm->flush();
        }
        if (!empty($this->okUploads) || !empty($this->failedUploads)) {
            $this->youtubeService->sendEmail('upload', $this->okUploads, $this->failedUploads, $this->errors);
        }
    }
}
