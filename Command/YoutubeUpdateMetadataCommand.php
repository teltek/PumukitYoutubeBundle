<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\NotificationService;
use Pumukit\YoutubeBundle\Services\VideoService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdateMetadataCommand extends Command
{
    private $documentManager;
    private $videoService;
    private $notificationService;
    private $logger;
    private $usePumukit1 = false;
    private $okUpdates;
    private $failedUpdates;
    private $errors;

    public function __construct(
        DocumentManager $documentManager,
        VideoService $videoService,
        NotificationService $notificationService,
        LoggerInterface $logger,
    ) {
        $this->documentManager = $documentManager;
        $this->videoService = $videoService;
        $this->notificationService = $notificationService;
        $this->logger = $logger;

        $this->okUpdates = [];
        $this->failedUpdates = [];
        $this->errors = [];

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
        $multimediaObjects = $this->getMultimediaObjectsInYoutubeToUpdate();
        $this->updateVideosInYoutube($multimediaObjects, $output);

        $this->notificationService->notificationOfUploadedVideoResults(
            $this->okUpdates,
            $this->failedUpdates,
            $this->errors
        );

        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->okUpdates = [];
        $this->failedUpdates = [];
        $this->errors = [];
        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    private function updateVideosInYoutube($multimediaObjects, OutputInterface $output)
    {
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $infoLog = sprintf(
                    '%s [%s] Started validate and updating MultimediaObject on YouTube with id %s',
                    __CLASS__,
                    __FUNCTION__,
                    $multimediaObject->getId()
                );
                $output->writeln($infoLog);

                $result = $this->videoService->updateVideoOnYoutube($multimediaObject);
                if (!$result) {
                    $this->failedUpdates[] = $multimediaObject;
                } else {
                    $this->okUpdates[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = sprintf(
                    '%s [%s] Update metadata video from the Multimedia Object with id %s failed: %s',
                    __CLASS__,
                    __FUNCTION__,
                    $multimediaObject->getId(),
                    $e->getMessage()
                );
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedUpdates[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function getMultimediaObjectsInYoutubeToUpdate(): array
    {
        $mongoObjectIds = $this->documentManager->getRepository(Youtube::class)->getDistinctIdsNotMetadataUpdated();
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

        return $this->documentManager->getRepository(MultimediaObject::class)->findBy($criteria);
    }
}
