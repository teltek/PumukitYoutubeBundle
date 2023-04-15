<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\NotificationService;
use Pumukit\YoutubeBundle\Services\VideoInsertService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUploadCommand extends Command
{
    private $documentManager;
    private $youtubeConfigurationService;
    private $videoInsertService;
    private $notificationService;
    private $logger;
    private $usePumukit1 = false;
    private $okUploads;
    private $failedUploads;
    private $errors;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        VideoInsertService $videoInsertService,
        NotificationService $notificationService,
        LoggerInterface $logger,
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoInsertService = $videoInsertService;
        $this->notificationService = $notificationService;
        $this->logger = $logger;

        $this->okUploads = [];
        $this->failedUploads = [];
        $this->errors = [];

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:upload')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Upload videos from Multimedia Objects to Youtube')
            ->setHelp(
                <<<'EOT'
Command to upload a controlled videos to Youtube.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->usePumukit1 = $input->getOption('use-pmk1');

        $newMultimediaObjects = $this->getNewMultimediaObjectsToUpload();
        $this->uploadVideosToYoutube($newMultimediaObjects, $output);

        $failureMultimediaObjects = $this->getUploadsByStatus([Youtube::STATUS_ERROR]);
        $this->uploadVideosToYoutube($failureMultimediaObjects, $output);

        if ($this->youtubeConfigurationService->uploadRemovedVideos()) {
            $removedStatus = [Youtube::STATUS_REMOVED];
            $removedYoutubeMultimediaObjects = $this->getUploadsByStatus($removedStatus);
            $this->uploadVideosToYoutube($removedYoutubeMultimediaObjects, $output);
        }

        $this->notificationService->notificationOfUploadedVideoResults(
            $this->okUploads,
            $this->failedUploads,
            $this->errors
        );

        return 0;
    }

    private function uploadVideosToYoutube($multimediaObjects, OutputInterface $output): void
    {
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $infoLog = sprintf(
                    '%s [%s] Started validate and uploading to Youtube of MultimediaObject with id %s',
                    __CLASS__,
                    __FUNCTION__,
                    $multimediaObject->getId()
                );
                $output->writeln($infoLog);

                $result = $this->videoInsertService->uploadVideoToYoutube($multimediaObject);
                if (!$result) {
                    $this->failedUploads[] = $multimediaObject;
                } else {
                    $this->okUploads[] = $multimediaObject;
                }
            } catch (\Exception $exception) {
                $errorLog = sprintf(
                    '%s [%s] The upload of the video from the Multimedia Object with id %s failed: %s',
                    __CLASS__,
                    __FUNCTION__,
                    $multimediaObject->getId(),
                    $exception->getMessage()
                );
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedUploads[] = $multimediaObject;
                $this->errors[] = $exception->getMessage();
            }
        }
    }

    private function createMultimediaObjectsToUploadQueryBuilder(): Builder
    {
        $array_pub_tags = $this->youtubeConfigurationService->publicationChannelsTags();

        if ($this->youtubeConfigurationService->syncStatus()) {
            $aStatus = [
                MultimediaObject::STATUS_PUBLISHED,
                MultimediaObject::STATUS_BLOCKED,
                MultimediaObject::STATUS_HIDDEN,
            ];
        } else {
            $aStatus = [MultimediaObject::STATUS_PUBLISHED];
        }

        $qb = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder();

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb->field('properties.origin')
            ->notEqual('youtube')
            ->field('status')
            ->in($aStatus)
            ->field('embeddedBroadcast.type')
            ->equals('public')
            ->field('tags.cod')
            ->all($array_pub_tags)
        ;
    }

    private function getNewMultimediaObjectsToUpload()
    {
        return $this->createMultimediaObjectsToUploadQueryBuilder()
            ->field('properties.youtube')
            ->exists(false)
            ->getQuery()
            ->execute()
        ;
    }

    private function getUploadsByStatus(array $statusArray = [])
    {
        $mmIds = $this->documentManager->getRepository(Youtube::class)->getDistinctMultimediaObjectIdsWithAnyStatus(
            $statusArray
        );

        return $this->createMultimediaObjectsToUploadQueryBuilder()
            ->field('_id')
            ->in($mmIds)
            ->getQuery()
            ->execute()
        ;
    }
}
