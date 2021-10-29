<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Repository\MultimediaObjectRepository;
use Pumukit\SchemaBundle\Repository\TagRepository;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Repository\YoutubeRepository;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdateStatusCommand extends ContainerAwareCommand
{
    /**
     * @var DocumentManager
     */
    protected $dm;
    /**
     * @var TagRepository
     */
    protected $tagRepo;
    /**
     * @var MultimediaObjectRepository
     */
    protected $mmobjRepo;
    /**
     * @var YoutubeRepository
     */
    protected $youtubeRepo;
    /**
     * @var YoutubeService
     */
    protected $youtubeService;

    protected $okUpdates = [];
    protected $failedUpdates = [];
    protected $errors = [];

    protected $usePumukit1 = false;
    /**
     * @var LoggerInterface
     */
    protected $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:update:status')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
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

    /**
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     * @throws \MongoException
     *
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $statusArray = [Youtube::STATUS_REMOVED, Youtube::STATUS_DUPLICATED];
        $youtubes = $this->youtubeRepo->getWithoutAnyStatus($statusArray);

        $this->updateVideoStatusInYoutube($youtubes, $output);
        $this->checkResultsAndSendEmail();
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->tagRepo = $this->dm->getRepository(Tag::class);
        $this->mmobjRepo = $this->dm->getRepository(MultimediaObject::class);
        $this->youtubeRepo = $this->dm->getRepository(Youtube::class);

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okUpdates = [];
        $this->failedUpdates = [];
        $this->errors = [];

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');

        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    /**
     * @param mixed $youtubes
     *
     * @throws \MongoException
     */
    protected function updateVideoStatusInYoutube($youtubes, OutputInterface $output)
    {
        foreach ($youtubes as $youtube) {
            $multimediaObject = $this->findByYoutubeIdAndPumukit1Id($youtube, false);
            if (null == $multimediaObject) {
                $multimediaObject = $this->findByYoutubeId($youtube);
                if (null == $multimediaObject) {
                    $msg = sprintf("No multimedia object for YouTube document %s\n", $youtube->getId());
                    echo $msg;
                    $this->logger->info($msg);
                }

                continue;
            }

            try {
                $infoLog = __CLASS__.
                    ' ['.__FUNCTION__.'] Started updating internal YouTube status video "'.
                    $youtube->getId().'"';
                $this->logger->info($infoLog);
                $output->writeln($infoLog);
                $outUpdate = $this->youtubeService->updateStatus($youtube);
                if (0 !== $outUpdate) {
                    $errorLog = __CLASS__.
                        ' ['.__FUNCTION__.'] Unknown error on the update in Youtube status video "'.
                        $youtube->getId().'": '.$outUpdate;
                    $this->logger->error($errorLog);
                    $output->writeln($errorLog);
                    $this->errors[] = $errorLog;

                    continue;
                }
                if ($multimediaObject) {
                    $this->okUpdates[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = __CLASS__.
                    ' ['.__FUNCTION__.'] The update of the Youtube status video "'.
                    $youtube->getId().'" failed: '.$e->getMessage();
                $this->logger->error($errorLog);
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

    /**
     * @param bool $pumukit1Id
     *
     * @throws \MongoException
     *
     * @return array|object|null
     */
    protected function findByYoutubeIdAndPumukit1Id(Youtube $youtube, $pumukit1Id = false)
    {
        $qb = $this->mmobjRepo
            ->createQueryBuilder()
            ->field('_id')->equals(new \MongoId($youtube->getMultimediaObjectId()))
            ->field('properties.origin')
            ->notEqual('youtube')
        ;

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')
                ->exists($pumukit1Id)
            ;
        }

        return $qb
            ->getQuery()
            ->getSingleResult()
        ;
    }

    /**
     * @throws \MongoException
     *
     * @return array|object|null
     */
    protected function findByYoutubeId(Youtube $youtube)
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('_id')->equals(new \MongoId($youtube->getMultimediaObjectId()))
            ->getQuery()
            ->getSingleResult()
        ;
    }
}
