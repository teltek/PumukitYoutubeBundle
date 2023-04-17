<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\Services\VideoDeleteService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeDeleteCommand extends Command
{
    private $documentManager;
    private $youtubeRepo;
    private $youtubeConfigurationService;
    private $tagService;
    private $youtubeService;
    private $videoDeleteService;
    private $okRemoved = [];
    private $failedRemoved = [];
    private $errors = [];
    private $usePumukit1 = false;
    private $logger;
    private $dryRun;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        VideoDeleteService $videoDeleteService,
        TagService $tagService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoDeleteService = $videoDeleteService;
        $this->tagService = $tagService;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:delete')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List multimedia objects to delete')
            ->setDescription('Command to delete videos from Youtube')
            ->setHelp(
                <<<'EOT'
Command to delete controlled videos from Youtube.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->usePumukit1 = $input->getOption('use-pmk1');
        $this->dryRun = (true === $input->getOption('dry-run'));

        // Use case:
        // Videos with YouTube document on PUBLISHED but status on PuMuKIT not allowed to be published
        // Ex: sync_status false and status hidden or blocked.
        $notPublishedMms = $this->notPublishedMultimediaObjects();
        $this->deleteVideosFromYoutube($notPublishedMms, $output);

        // Use case:
        // Videos published on YouTube but without PUCHYOUTUBE ( or configured tags ) on PuMuKIT
        // Ex: sync_status false and status hidden or blocked.
        $arrayPubTags = $this->youtubeConfigurationService->publicationChannelsTags();
        $youtubeMongoIds = $this->documentManager->getRepository(Youtube::class)->getDistinctFieldWithStatusAndForce(
            '_id',
            Youtube::STATUS_PUBLISHED,
            false
        );
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        $notCorrectTagMms = $this->getMultimediaObjectsInYoutubeWithoutTagCodes($publishedYoutubeIds, $arrayPubTags);
        $this->deleteVideosFromYoutube($notCorrectTagMms, $output);

        // Use case:
        // Videos published on YouTube but with EmbeddedBroadcast distinct of public
        $notPublicMms = $this->getMultimediaObjectsInYoutubeWithoutEmbeddedBroadcast($publishedYoutubeIds, 'public');
        $this->deleteVideosFromYoutube($notPublicMms, $output);

        // Use case:
        // Videos that was removed from PuMuKIT but have YouTube document.
        $orphanVideos = $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => Youtube::STATUS_TO_DELETE,
        ]);
        $this->deleteOrphanVideosFromYoutube($orphanVideos, $output);

        /*if (!$this->dryRun) {
            $this->checkResultsAndSendEmail();
        }*/

        return 0;
    }

    private function deleteVideosFromYoutube(iterable $multimediaObjects, OutputInterface $output)
    {
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                    .'] Started removing video from Youtube of MultimediaObject with id "'
                    .$multimediaObject->getId().'"';
                $output->writeln($infoLog);

                $result = $this->videoDeleteService->deleteVideoFromYouTubeByMultimediaObject($multimediaObject);
                if (!$result) {
                    $this->failedRemoved[] = $multimediaObject;
                } else {
                    $this->okRemoved[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                    .'] Removal of video from MultimediaObject with id "'.$multimediaObject->getId()
                    .'" has failed. '.$e->getMessage();

                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function deleteOrphanVideosFromYoutube(iterable $youtubeDocuments, OutputInterface $output)
    {
        foreach ($youtubeDocuments as $youtube) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                    .'] Started removing orphan video from Youtube with id "'
                    .$youtube->getId().'"';
                $this->logger->info($infoLog);
                $output->writeln($infoLog);
                $result = $this->videoDeleteService->deleteVideoFromYouTubeByYouTubeDocument($youtube);
                if (!$result) {
                    $this->failedRemoved[] = $youtube;
                } else {
                    $this->okRemoved[] = $youtube;
                }
                /*if (0 !== $outDelete) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                        .'] Unknown error in the removal from Youtube id "'
                        .$youtube->getId().'": '.$outDelete;
                    $this->logger->error($errorLog);
                    $output->writeln($errorLog);
                    $this->failedRemoved[] = $youtube;
                    $this->errors[] = $errorLog;

                    continue;
                }*/
                $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);
                $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy(['_id' => new ObjectId($youtube->getMultimediaObjectId())]);
                if ($multimediaObject) {
                    foreach ($multimediaObject->getTags() as $embeddedTag) {
                        if ($embeddedTag->isChildOf($youtubeTag)) {
                            $tag = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($embeddedTag->getId())]);
                            $youtube->setYoutubeAccount($tag->getProperty('login'));
                            $youtube->setStatus(Youtube::STATUS_UPLOADING);
                            $multimediaObject->removeProperty('youtube');
                            $multimediaObject->removeProperty('youtubeurl');
                            $this->documentManager->flush();
                        }
                    }
                }
                $this->okRemoved[] = $youtube;
            } catch (\Exception $e) {
                /*$errorLog = __CLASS__.' ['.__FUNCTION__
                    .'] Removal of video from Youtube with id "'.$youtube->getId()
                    .'" has failed. '.$e->getMessage();
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $youtube;
                $this->errors[] = $e->getMessage();*/
                $errorLog = __CLASS__.' ['.__FUNCTION__
                    .'] Removal of video from YouTube with id "'.$youtube->getId()
                    .'" has failed. '.$e->getMessage();

                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $youtube;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function notPublishedMultimediaObjects()
    {
        $youtubeMongoIds = $this->documentManager->getRepository(Youtube::class)->getDistinctFieldWithStatusAndForce(
            '_id',
            Youtube::STATUS_PUBLISHED,
            false
        );
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        if ($this->youtubeConfigurationService->syncStatus()) {
            $status = [MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN];
        } else {
            $status = [MultimediaObject::STATUS_PUBLISHED];
        }

        return $this->getMultimediaObjectsInYoutubeWithoutStatus($publishedYoutubeIds, $status);
    }

    private function getStringIds(iterable $mongoIds): array
    {
        $stringIds = [];
        foreach ($mongoIds as $mongoId) {
            $stringIds[] = $mongoId->__toString();
        }

        return $stringIds;
    }

    private function getMultimediaObjectsInYoutubeWithoutStatus(array $youtubeIds, array $status)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('status')->notIn($status)
            ->getQuery()
            ->execute()
        ;
    }

    private function getMultimediaObjectsInYoutubeWithoutTagCodes(array $youtubeIds, array $tagCodes)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('tags.cod')->notIn($tagCodes)
            ->getQuery()
            ->execute()
        ;
    }

    private function getMultimediaObjectsInYoutubeWithoutEmbeddedBroadcast(array $youtubeIds, $broadcastTypeId = EmbeddedBroadcast::TYPE_PUBLIC)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('embeddedBroadcast.type')->notEqual($broadcastTypeId)
            ->getQuery()
            ->execute()
        ;
    }

    private function createYoutubeQueryBuilder(array $youtubeIds = [])
    {
        $qb = $this->documentManager->getRepository(MultimediaObject::class)
            ->createQueryBuilder()
            ->field('properties.youtube')->in($youtubeIds)
            ->field('properties.origin')->notEqual('youtube');
        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb;
    }

    private function checkResultsAndSendEmail()
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if (null !== $youtubeTag) {
            foreach ($this->okRemoved as $mm) {
                if ($mm instanceof MultimediaObject) {
                    $youtubeDocument = $this->documentManager->getRepository(Youtube::class)->findOneBy(['status' => Youtube::STATUS_REMOVED]);
                    if ($mm->containsTagWithCod(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE) && $youtubeDocument) {
                        $this->tagService->removeTagFromMultimediaObject($mm, $youtubeTag->getId(), false);
                    }
                }
            }
            $this->documentManager->flush();
        }
        /*if (!empty($this->okRemoved) || !empty($this->failedRemoved)) {
            $this->youtubeService->sendEmail('remove', $this->okRemoved, $this->failedRemoved, $this->errors);
        }*/
    }
/*
    private function showMultimediaObjects(OutputInterface $output, $state, $multimediaObjects)
    {
        $numberMultimediaObjects = count($multimediaObjects);
        $output->writeln(
            [
                "\n",
                "<info>***** {$state} ***** ({$numberMultimediaObjects})</info>",
                "\n",
            ]
        );
        if ($numberMultimediaObjects > 0) {
            foreach ($multimediaObjects as $multimediaObject) {
                $output->writeln($multimediaObject->getId().' - '.$multimediaObject->getProperty('youtubeurl').' - '.$multimediaObject->getProperty('pumukit1id'));
            }
        }
    }

    private function showYoutubeMultimediaObjects(OutputInterface $output, $state, iterable $youtubeDocuments)
    {
        $numberYoutubeDocuments = count($youtubeDocuments);
        $output->writeln(
            [
                "\n",
                "<info>***** {$state} ***** ({$numberYoutubeDocuments})</info>",
                "\n",
            ]
        );
        if ($numberYoutubeDocuments > 0) {
            foreach ($youtubeDocuments as $youtube) {
                $output->writeln($youtube->getMultimediaObjectId().' - '.$youtube->getLink());
            }
        }
    }*/
}
