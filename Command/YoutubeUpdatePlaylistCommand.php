<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeUpdatePlaylistCommand extends ContainerAwareCommand
{
    const METATAG_PLAYLIST_COD = 'YOUTUBE';
    const METATAG_PLAYLIST_PATH = 'ROOT|YOUTUBE|';
    const DEFAULT_PLAYLIST_COD = 'YOUTUBECONFERENCES';
    const DEFAULT_PLAYLIST_TITLE = 'Conferences';

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
        $multimediaObjects = $this->createYoutubeQueryBuilder()
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute();

        foreach ($multimediaObjects as $multimediaObject) 
        {
            try
            {
                $outUpdatePlaylist = $this->youtubeService->updatePlaylist( $multimediaObject );
                if ($outUpdatePlaylist !== 0)
                {
                    $errorLog = sprintf('%s [%s] Unknown error in the update of Youtube Playlists of MultimediaObject with id %s: %s'
                                      , __CLASS__, __FUNCTION__, $multimediaObject->getId(), $outUpdatePlaylist);
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUpdates[] = $multimediaObject;
                    $this->errors[] = $errorLog;
                    continue;
                }
                $infoLog = sprintf('%s [%s] Updated all playlists of MultimediaObject with id %s', __CLASS__, __FUNCTION__, $multimediaObject->getId());
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $this->okUpdates[] = $multimediaObject;
            }
            catch (\Exception $e)
            {
                $errorLog = sprintf('%s [%s] Error: Couldn\'t update playlists of MultimediaObject with id %s [Exception]:%s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $e->getMessage());
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedUpdates[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
            }
        }
        $this->checkResultsAndSendEmail();
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');
        $this->broadcastRepo = $this->dm->getRepository('PumukitSchemaBundle:Broadcast');

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okUpdates = array();
        $this->failedUpdates = array();
        $this->errors = array();

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
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
