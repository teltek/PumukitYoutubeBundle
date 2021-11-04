<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUploadCommand extends Command
{
    public const PUB_DECISION_AUTONOMOUS = 'PUDEAUTO';

    private $documentManager;
    private $tagRepo;
    private $mmobjRepo;
    private $youtubeRepo;
    private $tagService;
    private $youtubeConfigurationService;
    private $syncStatus;
    private $uploadRemovedVideos;
    private $usePumukit1 = false;
    private $logger;
    private $youtubeService;
    private $okUploads = [];
    private $failedUploads = [];
    private $errors = [];

    public function __construct(
        DocumentManager $documentManager,
        YoutubeService $youtubeService,
        YoutubeConfigurationService $youtubeConfigurationService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeService = $youtubeService;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->logger = $logger;

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
        $newMultimediaObjects = $this->getNewMultimediaObjectsToUpload();
        $this->uploadVideosToYoutube($newMultimediaObjects, $output);

        $errorStatus = [
            Youtube::STATUS_ERROR,
        ];
        $failureMultimediaObjects = $this->getUploadsByStatus($errorStatus);
        $this->uploadVideosToYoutube($failureMultimediaObjects, $output);

        if ($this->uploadRemovedVideos) {
            $removedStatus = [Youtube::STATUS_REMOVED];
            $removedYoutubeMultimediaObjects = $this->getUploadsByStatus($removedStatus);
            $this->uploadVideosToYoutube($removedYoutubeMultimediaObjects, $output);
        }

        $this->checkResultsAndSendEmail();

        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->tagRepo = $this->documentManager->getRepository(Tag::class);
        $this->mmobjRepo = $this->documentManager->getRepository(MultimediaObject::class);
        $this->youtubeRepo = $this->documentManager->getRepository(Youtube::class);

        $this->syncStatus = $this->youtubeConfigurationService->syncStatus();
        $this->uploadRemovedVideos = $this->youtubeConfigurationService->uploadRemovedVideos();

        $this->okUploads = [];
        $this->failedUploads = [];
        $this->errors = [];
        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    private function uploadVideosToYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                if (!$this->youtubeService->getTrack($mm)) {
                    if ($this->youtubeService->hasPendingJobs($mm)) {
                        $this->logger->info('MultimediaObject with id '.$mm->getId().' have pending jobs.');
                    } else {
                        $this->logger->info('MultimediaObject with id '.$mm->getId().' haven\'t valid track for Youtube.');
                    }

                    continue;
                }

                $haveAccount = $this->checkIfMultimediaObjectHaveAccount($mm);
                if (!$haveAccount) {
                    $this->logger->warning('MultimediaObject with id '.$mm->getId().' haven\'t account for Youtube.');

                    continue;
                }

                $infoLog = sprintf(
                    '%s [%s] Started uploading to Youtube of MultimediaObject with id %s',
                    __CLASS__,
                    __FUNCTION__,
                    $mm->getId()
                );
                $this->logger->info($infoLog);
                $output->writeln($infoLog);
                $status = 'public';
                if ($this->syncStatus) {
                    $status = YoutubeService::$status[$mm->getStatus()];
                }

                $outUpload = $this->youtubeService->upload($mm, 27, $status, false);
                if (0 !== $outUpload) {
                    $errorLog = sprintf(
                        '%s [%s] Unknown error in the upload to Youtube of MultimediaObject with id %s: %s',
                        __CLASS__,
                        __FUNCTION__,
                        $mm->getId(),
                        $outUpload
                    );
                    $this->logger->error($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUploads[] = $mm;
                    $this->errors[] = $errorLog;

                    continue;
                }
                $this->okUploads[] = $mm;
            } catch (\Exception $e) {
                $errorLog = sprintf(
                    '%s [%s] The upload of the video from the Multimedia Object with id %s failed: %s',
                    __CLASS__,
                    __FUNCTION__,
                    $mm->getId(),
                    $e->getMessage()
                );
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedUploads[] = $mm;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function createMultimediaObjectsToUploadQueryBuilder()
    {
        $array_pub_tags = $this->youtubeConfigurationService->publicationChannelsTags();

        if ($this->syncStatus) {
            $aStatus = [
                MultimediaObject::STATUS_PUBLISHED,
                MultimediaObject::STATUS_BLOCKED,
                MultimediaObject::STATUS_HIDDEN,
            ];
        } else {
            $aStatus = [MultimediaObject::STATUS_PUBLISHED];
        }

        $qb = $this->mmobjRepo->createQueryBuilder();

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
        $mmIds = $this->youtubeRepo->getDistinctMultimediaObjectIdsWithAnyStatus($statusArray);

        return $this->createMultimediaObjectsToUploadQueryBuilder()
            ->field('_id')
            ->in($mmIds->toArray())
            ->getQuery()
            ->execute()
        ;
    }

    private function checkResultsAndSendEmail(): void
    {
        $youtubeTag = $this->tagRepo->findOneBy(['cod' => Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if (null != $youtubeTag) {
            foreach ($this->okUploads as $mm) {
                if (!$mm->containsTagWithCod(Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE)) {
                    $addedTags = $this->tagService->addTagToMultimediaObject($mm, $youtubeTag->getId(), false);
                }
            }
            $this->documentManager->flush();
        }
        if (!empty($this->okUploads) || !empty($this->failedUploads)) {
            $this->youtubeService->sendEmail('upload', $this->okUploads, $this->failedUploads, $this->errors);
        }
    }

    private function checkIfMultimediaObjectHaveAccount(MultimediaObject $mm): bool
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        $haveAccount = false;
        foreach ($mm->getTags() as $tag) {
            if ($tag->isChildOf($youtubeTag)) {
                $haveAccount = true;

                break;
            }
        }

        return $haveAccount;
    }
}
