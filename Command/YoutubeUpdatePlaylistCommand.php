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

class YoutubeUpdatePlaylistCommand extends ContainerAwareCommand
{
    const METATAG_PLAYLIST_COD = 'YOUTUBE';
    const METATAG_PLAYLIST_PATH = 'ROOT|YOUTUBE|';

    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;
    private $broadcastRepo = null;

    private $youtubeService;

    private $okUpdates = array();
    private $failedUpdates = array();
    private $errors = array();

    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:update:playlist')
            ->setDescription('Update Youtube playlists from Multimedia Objects')
            ->setHelp(<<<EOT
Command to update playlist in Youtube.

EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();        

        $this->updatePlaylistChange();
        $youtubes = $this->youtubeRepo->getWithStatusAndUpdatePlaylist(Youtube::STATUS_PUBLISHED, true);

        $this->updateYoutubePlaylist($youtubes, $output);
        $this->checkResultsAndSendEmail();
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository("PumukitSchemaBundle:Tag");
        $this->mmobjRepo = $this->dm->getRepository("PumukitSchemaBundle:MultimediaObject");
        $this->youtubeRepo = $this->dm->getRepository("PumukitYoutubeBundle:Youtube");
        $this->broadcastRepo = $this->dm->getRepository("PumukitSchemaBundle:Broadcast");

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okUpdates = array();
        $this->failedUpdates = array();
        $this->errors = array();

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
    }

    private function updateYoutubePlaylist($youtubes, OutputInterface $output)
    {
        foreach ($youtubes as $youtube){

            $multimediaObject = $this->createYoutubeQueryBuilder()
                ->field('_id')->equals(new \MongoId($youtube->getMultimediaObjectId()))
                ->getQuery()
                ->getSingleResult();

            $playlistTagIds = $this->getPlaylistTagIds($multimediaObject);

            foreach ($playlistTagIds as $playlistTagId) {
                try {
                    $outUpdatePlaylist = $this->youtubeService->updatePlaylist($multimediaObject, $playlistTagId);
                    if (0 !== $outUpdatePlaylist) {
                        $errorLog = __CLASS__.' ['.__FUNCTION__
                          .'] Unknown error in the update of Youtube Playlist of MultimediaObject with id "'
                          .$multimediaObject->getId().' and Tag with id "'. $playlistTagId.'": ' . $outUpdatePlaylist;
                        $this->logger->addError($errorLog);
                        $output->writeln($errorLog);
                        $this->failedUpdates[] = $multimediaObject;
                        $this->errors[] = $errorLog;
                        continue;
                    }
                    $infoLog = __CLASS__." [".__FUNCTION__
                      ."] Updated playlist of MultimediaObject with id ".$multimediaObject->getId();
                    $this->logger->addInfo($infoLog);
                    $output->writeln($infoLog);
                    $this->okUpdates[] = $multimediaObject;
                } catch (\Exception $e) {
                    $errorLog = __CLASS__." [".__FUNCTION__
                      ."] Error on updating playlist of MultimediaObject with id ".$multimediaObject->getId();
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUpdates[] = $multimediaObject;
                    $this->errors[] = $e->getMessage();
                }
            }
        }

        return 0;
    }

    private function getPlaylistTagIds(MultimediaObject $multimediaObject)
    {
        $playlistTagIds = array();
        $youtube = $this->youtubeRepo->find($multimediaObject->getProperty('youtube'));
        foreach ($multimediaObject->getTags() as $embedTag) {
            if ((0 === strpos($embedTag->getPath(), self::METATAG_PLAYLIST_PATH)) && ($embedTag->getCod() !== self::METATAG_PLAYLIST_COD)) {
                $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());
                if (null != $playlistTag) {
                    $playlistTagIds[] = $playlistTag->getId();
                }
            }
            foreach ($youtube->getPlaylists() as $playlistId => $playlist) {
                $playlistTag = $this->getTagByYoutubeProperty($playlistId);
                if (null != $playlistTag) {
                    if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                        $playlistTagIds[] = $playlistTag->getId();
                    }
                }
            }
        }

        return $playlistTagIds;
    }

    private function updatePlaylistChange()
    {
        $multimediaObjects = $this->createYoutubeQueryBuilder()
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute();

        foreach ($multimediaObjects as $multimediaObject) {
            $youtube = $this->youtubeRepo->find($multimediaObject->getProperty('youtube'));
            foreach ($multimediaObject->getTags() as $embedTag) {
                if ((0 === strpos($embedTag->getPath(), self::METATAG_PLAYLIST_PATH)) && ($embedTag->getCod() !== self::METATAG_PLAYLIST_COD)) {
                    $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());
                    if (!array_key_exists($playlistTag->getProperty('youtube'), $youtube->getPlaylists())) {
                        $youtube->setUpdatePlaylist(true);
                        $this->dm->persist($youtube);
                        $this->dm->flush();
                        break;
                    }
                }
            }
            foreach ($youtube->getPlaylists() as $playlistId => $playlist) {
                $playlistTag = $this->getTagByYoutubeProperty($playlistId);
                if (null == $playlistTag) {
                    $youtube->setUpdatePlaylist(true);
                    $this->dm->persist($youtube);
                    $this->dm->flush();
                    break;
                }
                if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                    $youtube->setUpdatePlaylist(true);
                    $this->dm->persist($youtube);
                    $this->dm->flush();
                    break;
                }
            }
        }
    }

    private function createYoutubeQueryBuilder()
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.pumukit1id')->exists(false);
    }

    private function checkResultsAndSendEmail()
    {
        if (!empty($this->errors)) {
            $this->youtubeService->sendEmail('playlist update', $this->okUpdates, $this->failedUpdates, $this->errors);
        }
    }

    private function getTagByYoutubeProperty($playlistId)
    {
        return $this->tagRepo->createQueryBuilder()
          ->field('properties.youtube')->equals($playlistId)
          ->getQuery()->getSingleResult();
    }
}