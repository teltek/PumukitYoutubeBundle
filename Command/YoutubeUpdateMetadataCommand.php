<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdateMetadataCommand extends ContainerAwareCommand
{
    private $dm;
    private $tagRepo;
    private $mmobjRepo;
    private $youtubeRepo;

    private $youtubeService;

    private $okUpdates = [];
    private $failedUpdates = [];
    private $errors = [];

    private $usePumukit1 = false;

    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:update:metadata')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Update Youtube metadata from Multimedia Objects')
            ->setHelp(
                <<<'EOT'
Sync YouTube Service metadata with local metadata(title, description...). Meaning, update the metadata of the YouTube service getting the info from the local multimedia objects.

PERFORMANCE NOTE: This command has a good performance because only use the multimedia objects updated from the last synchronization.

EOT
        )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $mms = $this->getMultimediaObjectsInYoutubeToUpdate();
        $this->updateVideosInYoutube($mms, $output);
        $this->checkResultsAndSendEmail();
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okUpdates = [];
        $this->failedUpdates = [];
        $this->errors = [];

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');

        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    private function updateVideosInYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                $infoLog = __CLASS__.
                    ' ['.__FUNCTION__.'] Started updating Youtube video of MultimediaObject with id "'.
                    $mm->getId().'"';
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $outUpdate = $this->youtubeService->updateMetadata($mm);
                if (0 !== $outUpdate) {
                    $errorLog = __CLASS__.
                        ' ['.__FUNCTION__.'] Uknown output on the update in Youtube video of MultimediaObject with id "'.
                        $mm->getId().'": '.$outUpdate;
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUpdates[] = $mm;
                    $this->errors[] = $errorLog;
                }
                $this->okUpdates[] = $mm;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.
                    ' ['.__FUNCTION__.'] The update of the video from the Multimedia Object with id "'.
                    $mm->getId().'" failed: '.$e->getMessage();
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedUpdates[] = $mm;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function getMultimediaObjectsInYoutubeToUpdate()
    {
        $mongoObjectIds = $this->youtubeRepo->getDistinctIdsNotMetadataUpdated();
        $youtubeIds = [];
        foreach ($mongoObjectIds as $mongoObjectId) {
            $youtubeIds[] = $mongoObjectId->__toString();
        }

        $mms = $this->mmobjRepo
            ->createQueryBuilder()
            ->field('properties.origin')
            ->notEqual('youtube')
            ->field('properties.youtube')
            ->in($youtubeIds)
        ;

        if (!$this->usePumukit1) {
            $mms->field('properties.pumukit1id')
                ->exists(false)
            ;
        }

        return $mms
            ->getQuery()
            ->execute()
        ;
    }

    private function checkResultsAndSendEmail()
    {
        if (!empty($this->errors)) {
            $this->youtubeService->sendEmail('metadata update', $this->okUpdates, $this->failedUpdates, $this->errors);
        }
    }
}
