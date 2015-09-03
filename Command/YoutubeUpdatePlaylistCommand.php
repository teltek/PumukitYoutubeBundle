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

            $mm = $this->createYoutubeQueryBuilder()
                ->field('_id')->equals(new \MongoId($youtube->getMultimediaObjectId()))
                ->getQuery()
                ->getSingleResult();

            $playlistTagId = $this->getPlaylistTagId($mm);
            if (null == $playlistTagId) continue;

            try {
                $outUpdatePlaylist = $this->youtubeService->updatePlaylist($mm, $playlistTagId);
                if (0 !== $outUpdatePlaylist) {
                    $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] Error in the update of Youtube Playlist of MultimediaObject with id "'.$mm->getId().' and Tag with id "'. $playlistTagId.'": ' . $outUpdatePlaylist);
                    $output->writeln('Error in the update of Youtube Playlist of MultimediaObject with id "'.$mm->getId().' and Tag with id "'. $playlistTagId.'": ' . $outUpdatePlaylist);
                    $this->failedUpdates[] = $mm;
                    $this->errors[] = $outUpdatePlaylist;
                    continue;
                }
                $this->logger->addInfo(__CLASS__." [".__FUNCTION__."] Updated playlist of MultimediaObject with id ".$multimediaObject->getId());
                $output->writeln("Updated playlist of MultimediaObject with id ".$multimediaObject->getId());
                $this->okUpdates[] = $mm;
            } catch (\Exception $e) {
                $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error on updating playlist of MultimediaObject with id ".$multimediaObject->getId());
                $output->writeln("Error on updating playlist of MultimediaObject with id ".$multimediaObject->getId());
                $this->failedUpdates[] = $mm;
                $this->errors[] = substr($e->getMessage(), 0, 100);
            }
        }

        return 0;
    }

    private function getPlaylistTagId(MultimediaObject $mm)
    {
        $playlistTagId = null;
        $embedTag = null;
        foreach ($mm->getTags() as $tag) {
          if ((0 === strpos($tag->getPath(), "ROOT|YOUTUBE|")) && ($tag->getCod() !== 'YOUTUBE')) {
                $embedTag = $tag;
                break;
            }
        }
        if ($embedTag) {
            $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());
            if ($playlistTag) $playlistTagId = $playlistTag->getId();
        }

        return $playlistTagId;
    }

    private function updatePlaylistChange()
    {
        $mms = $this->createYoutubeQueryBuilder()
            ->field('tags.properties.playlist')->exists(true)
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute();

        foreach ($mms as $mm) {
            $youtube = $this->youtubeRepo->find($mm->getProperty('youtube'));
            foreach ($mm->getTags() as $embedTag) {
                if ((0 === strpos($tag->getPath(), "ROOT|YOUTUBE|")) && ($tag->getCod() !== 'YOUTUBE')) {
                    $embedTag = $tag;
                    break;
                }
            }
            if (null != $embedTag) {
                if ($youtube->getPlaylist() !== $embedTag->getProperty('playlist')){
                    $youtube->setUpdatePlaylist(true);
                    $this->dm->persist($youtube);
                }
            }
        }
        $this->dm->flush();
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
}