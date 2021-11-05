<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdateMetadataCommand extends Command
{
    private $documentManager;
    private $tagRepo;
    private $mmobjRepo;
    private $youtubeRepo;
    private $youtubeService;
    private $okUpdates = [];
    private $failedUpdates = [];
    private $errors = [];
    private $usePumukit1 = false;
    private $logger;

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
            ->setName('pumukit:youtube:update:metadata')
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mms = $this->getMultimediaObjectsInYoutubeToUpdate();
        $this->updateVideosInYoutube($mms, $output);
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

    private function updateVideosInYoutube(array $mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__.'] Started updating Youtube video of MultimediaObject with id "'.$mm->getId().'"';
                $this->logger->info($infoLog);
                $output->writeln($infoLog);
                $outUpdate = $this->youtubeService->updateMetadata($mm);
                if (0 !== $outUpdate) {
                    $errorLog = __CLASS__.
                        ' ['.__FUNCTION__.'] Uknown output on the update in Youtube video of MultimediaObject with id "'.
                        $mm->getId().'": '.$outUpdate;
                    $this->logger->error($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUpdates[] = $mm;
                    $this->errors[] = $errorLog;
                }
                $this->okUpdates[] = $mm;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.
                    ' ['.__FUNCTION__.'] The update of the video from the Multimedia Object with id "'.
                    $mm->getId().'" failed: '.$e->getMessage();
                $this->logger->error($errorLog);
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

        $criteria = [
            'properties.origin' => ['$ne' => 'youtube'],
            'properties.youtube' => ['$in' => $youtubeIds],
        ];

        if (!$this->usePumukit1) {
            $criteria['properties.pumukit1id'] = ['$exists' => false];
        }

        return $this->mmobjRepo->findBy($criteria);
    }

    private function checkResultsAndSendEmail(): void
    {
        if (!empty($this->errors)) {
            $this->youtubeService->sendEmail('metadata update', $this->okUpdates, $this->failedUpdates, $this->errors);
        }
    }
}
