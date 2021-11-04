<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdateStatusCommand extends Command
{
    protected $documentManager;
    protected $tagRepo;
    protected $mmobjRepo;
    protected $youtubeRepo;
    protected $youtubeService;
    protected $okUpdates = [];
    protected $failedUpdates = [];
    protected $errors = [];
    protected $usePumukit1 = false;
    protected $logger;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeService $youtubeService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeService = $youtubeService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:update:status')
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statusArray = [Youtube::STATUS_REMOVED, Youtube::STATUS_DUPLICATED];
        $youtubes = $this->youtubeRepo->getWithoutAnyStatus($statusArray);

        $this->updateVideoStatusInYoutube($youtubes, $output);
        $this->checkResultsAndSendEmail();

        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->tagRepo = $this->documentManager->getRepository(Tag::class);
        $this->mmobjRepo = $this->documentManager->getRepository(MultimediaObject::class);
        $this->youtubeRepo = $this->documentManager->getRepository(Youtube::class);
        $this->okUpdates = [];
        $this->failedUpdates = [];
        $this->errors = [];
        $this->usePumukit1 = $input->getOption('use-pmk1');
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
            ->field('_id')->equals(new ObjectId($youtube->getMultimediaObjectId()))
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
            ->field('_id')->equals(new ObjectId($youtube->getMultimediaObjectId()))
            ->getQuery()
            ->getSingleResult()
        ;
    }
}
