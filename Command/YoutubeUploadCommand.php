<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Psr\Log\LoggerInterface;

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
    private $broadcastRepo = null;

    private $logger;
    private $youtubeService;
    private $senderService;
    private $translator;
    private $router;

    private $okUploads = array();
    private $failedUploads = array();
    private $errors = array();

    protected function configure()
    {
        $this
            ->setName('youtube:upload')
            ->setDescription('Upload videos from Multimedia Objects to Youtube')
            ->setHelp(<<<EOT
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
                             Youtube::STATUS_UPDATE_ERROR
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
        $this->tagRepo = $this->dm->getRepository("PumukitSchemaBundle:Tag");
        $this->mmobjRepo = $this->dm->getRepository("PumukitSchemaBundle:MultimediaObject");
        $this->youtubeRepo = $this->dm->getRepository("PumukitYoutubeBundle:Youtube");
        $this->broadcastRepo = $this->dm->getRepository("PumukitSchemaBundle:Broadcast");

        $container = $this->getContainer();
        $this->youtubeService = $container->get('pumukityoutube.youtube');
        $this->senderService = $container->get('pumukit_notification.sender');
        $this->translator = $container->get('translator');
        $this->router = $container->get('router');
        $this->logger = $container->get('monolog.logger.youtube');

        $this->okUploads = array();
        $this->failedUploads = array();
        $this->errors = array();
    }

    private function uploadVideosToYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            $playlistTagIds = $this->getPlaylistTagIds($mm, $output);
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                  .'] Started uploading to Youtube of MultimediaObject with id "'.$mm->getId().'"';
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $outUpload = $this->youtubeService->upload($mm, 27, 'public', false);
                if (0 !== $outUpload) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                      .'] Unknown error in the upload to Youtube of MultimediaObject with id "'
                      .$mm->getId().'": ' . $outUpload;
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUploads[] = $mm;
                    $this->errors[] = $errorLog;
                    continue;
                }
                foreach ($playlistTagIds as $playlistTagId) {
                    $infoLog = __CLASS__.' ['.__FUNCTION__
                      .'] Started moving video to Youtube playlist assign with Tag id "'
                      .$playlistTagId.'" of MultimediaObject with id "'.$mm->getId().'"';
                    $this->logger->addInfo($infoLog);
                    $output->writeln($infoLog);
                    $outMoveToList = $this->youtubeService->moveToList($mm, $playlistTagId);
                    if (0 !== $outMoveToList) {
                        $errorLog = __CLASS__.' ['.__FUNCTION__
                          .'] Unknown out in the move list to Youtube of MultimediaObject with id "'
                          .$mm->getId().'": '. $outMoveToList;
                        $this->logger->addError($errorLog);
                        $output->writeln($errorLog);
                        $this->failedUploads[] = $mm;
                        $this->errors[] = $errorLog;
                        continue;
                    }
                }
                $this->okUploads[] = $mm;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  .'] The upload of the video from the Multimedia Object with id "'
                  .$mm->getId().'" failed: '.$e->getMessage();
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedUploads[] = $mm;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function createMultimediaObjectsToUploadQueryBuilder()
    {
        $publicBroadcast = $this->broadcastRepo->findPublicBroadcast();

        return $this->mmobjRepo->createQueryBuilder()
          ->field('properties.pumukit1id')->exists(false)
          ->field('status')->equals(MultimediaObject::STATUS_PUBLISHED)
          ->field('broadcast')->references($publicBroadcast)
          /* ->field('tags.cod')->equals('IMPORTANT') TODO When Tag with code 'IMPORTANT' is done ('autónomo' in Pumukit1.8) */ // TODO review !!!!!!!!!!!
          ->field('tags.cod')->all(array(self::PUB_CHANNEL_YOUTUBE, self::PUB_DECISION_AUTONOMOUS));
    }

    private function getNewMultimediaObjectsToUpload()
    {
        return $this->createMultimediaObjectsToUploadQueryBuilder()
          ->field('properties.youtube')->exists(false)
          ->getQuery()
          ->execute();
    }

    private function getUploadsByStatus($statusArray=array())
    {
        $mmIds = $this->youtubeRepo->getDistinctMultimediaObjectIdsWithAnyStatus($statusArray);

        return $this->createMultimediaObjectsToUploadQueryBuilder()
          ->field('_id')->in($mmIds->toArray())
          ->getQuery()
          ->execute();
    }

    private function getPlaylistTagIds($mm, OutputInterface $output)
    {
        $playlistTagIds = array();
        foreach ($mm->getTags() as $embedTag) {
            if ((0 === strpos($embedTag->getPath(), self::METATAG_PLAYLIST_PATH)) && (self::METATAG_PLAYLIST_COD !== $embedTag->getCod())) {
                $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());
                if (null != $playlistTag) {
                    $playlistTagIds[] = $playlistTag->getId();
                } else {
                    $output->writeln('MultimediaObject with id "'.$mm->getId().'" does have an EmbedTag with path "'.$embedTag->getPath().'" and code "'.$embedTag->getCod().'" but does not exist in Tag repository');
                }
            }
        }
        if (null == $playlistTagIds) {
            $output->writeln('MultimediaObject with id "'.$mm->getId().'" does not have any EmbedTag with path starting with "'.self::METATAG_PLAYLIST_PATH .'" so we search for Tag with code "'. self::DEFAULT_PLAYLIST_COD . '" as default Youtube playlist.');
            $playlistTag = $this->tagRepo->findOneByCod(self::DEFAULT_PLAYLIST_COD);
            if (null == $playlistTag) {
                $playlistTag = $this->createDefaultPlaylist();
                $output->writeln('There is no Tag with code "'.self::DEFAULT_PLAYLIST_COD.'" as default Youtube playlist so we created it with resultant id "'.$playlistTag->getId().'".');
            }
            $playlistTagIds[] = $playlistTag->getId();
        }
        return $playlistTagIds;
    }

    private function checkResultsAndSendEmail()
    {
        $youtubeTag = $this->tagRepo->findByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            foreach ($this->okUploads as $mm){
                if (!$mm->containsTagWithCod(self::PUB_CHANNEL_YOUTUBE)) {
                    $addedTags = $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId(), false);
                }
            }
            $this->dm->flush();
        }
        if (!empty($this->okUploads) || !empty($this->failedUploads)) {
            $this->youtubeService->sendEmail('upload', $this->okUploads, $this->failedUploads, $this->errors);
        }
    }

    private function createDefaultPlaylist()
    {
        $youtubeTag = $this->tagRepo->findOneByCod(self::METATAG_PLAYLIST_COD);
        $playlistTag = new Tag();
        $playlistTag->setCod(self::DEFAULT_PLAYLIST_COD);
        $playlistTag->setParent($youtubeTag);
        $playlistTag->setMetatag(false);
        $playlistTag->setDisplay(true);
        $playlistTag->setTitle(self::DEFAULT_PLAYLIST_TITLE, 'en');
        $this->dm->persist($playlistTag);
        $this->dm->flush();

        return $playlistTag;
    }
}