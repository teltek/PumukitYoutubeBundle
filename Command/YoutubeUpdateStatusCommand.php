<?php

namespace Pumukit\YoutubeBundle\Command;

use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdateStatusCommand extends ContainerAwareCommand
{
    protected $dm;
    protected $tagRepo;
    protected $mmobjRepo;
    protected $youtubeRepo;

    protected $youtubeService;

    protected $okUpdates = [];
    protected $failedUpdates = [];
    protected $errors = [];

    protected $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:update:status')
            ->setDescription('Update local YouTube status of the video')
            ->setHelp(
                <<<'EOT'
Update the local YouTube status stored in PuMuKIT YouTube collection, getting the info from the YouTube service using the API. If enabled it sends an email with a summary.

The statuses removed, notified error and duplicated are not updated.

PERFORMANCE NOTE: This command has a bad performance because use all the multimedia objects uploaded in Youtube Service. (See youtube:update:pendingstatus)

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $statusArray = [Youtube::STATUS_REMOVED, Youtube::STATUS_NOTIFIED_ERROR, Youtube::STATUS_DUPLICATED];
        $youtubes = $this->youtubeRepo->getWithoutAnyStatus($statusArray);

        $this->updateVideoStatusInYoutube($youtubes, $output);
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
    }

    protected function updateVideoStatusInYoutube($youtubes, OutputInterface $output)
    {
        foreach ($youtubes as $youtube) {
            $multimediaObject = $this->findByYoutubeIdAndPumukit1Id($youtube, false);
            if (null == $multimediaObject) {
                $multimediaObject = $this->findByYoutubeId($youtube);
                if (null == $multimediaObject) {
                    $msg = sprintf("No multimedia object for YouTube document %s\n", $youtube->getId());
                    echo $msg;
                    $this->logger->addInfo($msg);
                }

                continue;
            }

            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                  .'] Started updating internal YouTube status video "'.$youtube->getId().'"';
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $outUpdate = $this->youtubeService->updateStatus($youtube);
                if (0 !== $outUpdate) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                      .'] Unknown error on the update in Youtube status video "'
                      .$youtube->getId().'": '.$outUpdate;
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->errors[] = $errorLog;

                    continue;
                }
                if ($multimediaObject) {
                    $this->okUpdates[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  .'] The update of the Youtube status video "'.$youtube->getId()
                  .'" failed: '.$e->getMessage();
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                if ($multimediaObject) {
                    $this->failedUpdates[] = $multimediaObject;
                }
                $this->errors[] = $e->getMessage();
            }
        }
    }

    protected function checkResultsAndSendEmail()
    {
        if (!empty($this->errors)) {
            $this->youtubeService->sendEmail('status update', $this->okUpdates, $this->failedUpdates, $this->errors);
        }
    }

    protected function findByYoutubeIdAndPumukit1Id(Youtube $youtube, $pumukit1Id = false)
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->equals($youtube->getId())
            ->field('properties.origin')->notEqual('youtube')
            ->field('properties.pumukit1id')->exists($pumukit1Id)
            ->getQuery()
            ->getSingleResult()
        ;
    }

    protected function findByYoutubeId(Youtube $youtube)
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->equals($youtube->getId())
            ->getQuery()
            ->getSingleResult()
        ;
    }
}
