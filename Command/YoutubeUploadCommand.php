<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubeService;

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
    private $tagService;
    private $syncStatus;

    private $uploadRemovedVideos;
    private $usePumukit1 = false;

    private $logger;
    private $youtubeService;

    private $okUploads = array();
    private $failedUploads = array();
    private $errors = array();

    protected function configure()
    {
        $this
            ->setName('youtube:upload')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Upload videos from Multimedia Objects to Youtube')
            ->setHelp(
                <<<'EOT'
Command to upload a controlled videos to Youtube.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $newMultimediaObjects = $this->getNewMultimediaObjectsToUpload();
        $this->uploadVideosToYoutube($newMultimediaObjects, $output);

        $errorStatus = array(
            Youtube::STATUS_HTTP_ERROR,
            Youtube::STATUS_ERROR,
            Youtube::STATUS_UPDATE_ERROR,
        );
        $failureMultimediaObjects = $this->getUploadsByStatus($errorStatus);
        $this->uploadVideosToYoutube($failureMultimediaObjects, $output);

        if ($this->uploadRemovedVideos) {
            $removedStatus = [Youtube::STATUS_REMOVED];
            $removedYoutubeMultimediaObjects = $this->getUploadsByStatus($removedStatus);
            $this->uploadVideosToYoutube($removedYoutubeMultimediaObjects, $output);
        }

        $this->checkResultsAndSendEmail();
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $container = $this->getContainer();
        $this->youtubeService = $container->get('pumukityoutube.youtube');
        $this->logger = $container->get('monolog.logger.youtube');

        $this->syncStatus = $container->getParameter('pumukit_youtube.sync_status');
        $this->uploadRemovedVideos = $container->getParameter('pumukit_youtube.upload_removed_videos');

        $this->okUploads = array();
        $this->failedUploads = array();
        $this->errors = array();

        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    private function uploadVideosToYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                if (!$this->youtubeService->getTrack($mm)) {
                    if ($this->youtubeService->hasPendingJobs($mm)) {
                        $this->logger->addInfo('MultimediaObject with id '.$mm->getId().' have pending jobs.');
                    } else {
                        $this->logger->addInfo('MultimediaObject with id '.$mm->getId().' haven\'t valid track for Youtube.');
                    }

                    continue;
                }

                $haveAccount = $this->checkIfMultimediaObjectHaveAccount($mm);
                if (!$haveAccount) {
                    $this->logger->warning('MultimediaObject with id '.$mm->getId().' haven\'t account for Youtube.');
                    continue;
                }

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
                MultimediaObject::STATUS_BLOCKED,
                MultimediaObject::STATUS_HIDDEN,
            );
        } else {
            $aStatus = array(MultimediaObject::STATUS_PUBLISHED);
        }

        $qb = $this->mmobjRepo->createQueryBuilder();

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb->field('properties.origin')
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

    private function checkIfMultimediaObjectHaveAccount(MultimediaObject $mm)
    {
        $youtubeTag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => 'YOUTUBE'));
        $haveAccount = false;
        foreach ($mm->getTags() as $tag) {
            if ($tag->isChildOf($youtubeTag)) {
                $haveAccount = true;
                break;
            }
        }

        return $haveAccount;
    }
}
