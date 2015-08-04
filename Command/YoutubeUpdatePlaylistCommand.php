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

    private $okUploads = array();
    private $failedUploads = array();
    private $errors = array();
    private $correct = false;
    private $failure = false;

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

    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository("PumukitSchemaBundle:Tag");
        $this->mmobjRepo = $this->dm->getRepository("PumukitSchemaBundle:MultimediaObject");
        $this->youtubeRepo = $this->dm->getRepository("PumukitYoutubeBundle:Youtube");
        $this->broadcastRepo = $this->dm->getRepository("PumukitSchemaBundle:Broadcast");

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okUploads = array();
        $this->failedUploads = array();
        $this->errors = array();
        $this->correct = false;
        $this->failure = false;

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
    }

    private function updateYoutubePlaylist($youtubes, OutputInterface $output)
    {
        foreach ($youtubes as $youtube){

            $mm = $this->mmobjRepo->find($youtube->getMultimediaObjectId());

            $playlistTagId = $this->getPlaylistTagId($mm);
            if (null == $playlistTagId) continue;

            try {
                $outUpdatePlaylist = $this->youtubeService->updatePlaylist($mm, $playlistTagId);
            } catch (\Exception $e) {
                $this->logger->addInfo(__CLASS__." [".__FUNCTION__."] Error on updating playlist of MultimediaObject with id ".$multimediaObject->getId());
                $output->writeln("Error on updating playlist of MultimediaObject with id ".$multimediaObject->getId());
                return -1;
            }
        }

        return 0;
    }

    private function getPlaylistTagId(MultimediaObject $mm)
    {
        $playlistTagId = null;
        $embedTag = null;
        foreach ($mm->getTags() as $tag) {
            if (0 === strpos($tag->getPath(), "ROOT|YOUTUBE|")) {
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
        $mms = $this->mmobjRepo->createQueryBuilder()
            ->field('tags.properties.playlist')->exists(true)
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute();

        foreach ($mms as $mm) {
            $youtube = $this->youtubeRepo->find($mm->getProperty('youtube'));
            foreach ($mm->getTags() as $embedTag) {
                if (0 === strpos($tag->getPath(), "ROOT|YOUTUBE|")) {
                    $embedTag = $tag;
                    break;
                }
            }
            if ($embedTag) {
                if ($youtube->getPlaylist !== $embedTag->getProperty('playlist')){
                    $youtube->setUpdatePlaylist(true);
                    $this->dm->persist($youtube);
                }
            }
        }
        $this->dm->flush();
    }
}