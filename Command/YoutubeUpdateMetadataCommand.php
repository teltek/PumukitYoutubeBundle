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

class YoutubeUpdateMetadataCommand extends ContainerAwareCommand
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
            ->setName('youtube:update:metadata')
            ->setDescription('Update Youtube metadata from Multimedia Objects')
            ->setHelp(<<<EOT
Command to upload a controlled videos to Youtube.

EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        $mms = $this->getMultimediaObjectsInYoutubeToUpdate();
        $this->updateVideosInYoutube($mms, $output);
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

    private function updateVideosInYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Started updating Youtube video of MultimediaObject with id "'.$mm->getId().'"');
                $output->writeln('Started updating Youtube video of MultimediaObject with id "'.$mm->getId().'"');
                $outUpdate = $this->youtubeService->updateMetadata($mm);
                if (0 !== $outUpdate) {
                    $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Uknown output on the update in Youtube video of MultimediaObject with id "'.$mm->getId().'"');
                    $output->writeln('Unknown output on the update in Youtube video of MultimediaObject with id "'.$mm->getId().'"');
                }
                $this->okUpdates[] = $mm;
            } catch (\Exception $e) {
                $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] The update of the video from the Multimedia Object with id "'.$mm->getId().'" failed: '.$e->getMessage());
                $output->writeln('The update of the video from the Multimedia Object with id "'.$mm->getId().'" failed: '.$e->getMessage());
                $this->failedUpdates[] = $mm;
                $this->errors[] = substr($e->getMessage(), 0, 100);
            }
        }
    }

    private function getMultimediaObjectsInYoutubeToUpdate()
    {
        $youtubeIds = $this->youtubeRepo->getDistinctIdsNotMetadataUpdated();

        $mms = $this->mmobjRepo->createQueryBuilder()
          ->field('properties.youtube')->exists(true)
          ->field('properties.youtube')->in($youtubeIds->toArray())
          ->getQuery()
          ->execute();

        return $mms;
    }

    private function checkResultsAndSendEmail()
    {
        if (!empty($this->errors)) {
            $this->youtubeService->sendEmail('metadata update', $this->okUpdates, $this->failedUpdates, $this->errors);
        }
    }
}
