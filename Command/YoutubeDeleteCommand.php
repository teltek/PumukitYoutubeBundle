<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Repository\TagRepository;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Repository\YoutubeRepository;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class YoutubeDeleteCommand.
 */
class YoutubeDeleteCommand extends ContainerAwareCommand
{
    public const PUB_CHANNEL_WEBTV = 'PUCHWEBTV';
    public const PUB_DECISION_AUTONOMOUS = 'PUDEAUTO';
    /**
     * @var DocumentManager
     */
    private $dm;
    /**
     * @var TagRepository
     */
    private $tagRepo;
    /**
     * @var YoutubeRepository
     */
    private $youtubeRepo;
    /**
     * @var YoutubeService
     */
    private $youtubeService;
    /**
     * @var TagService
     */
    private $tagService;
    private $okRemoved = [];
    private $failedRemoved = [];
    private $errors = [];
    private $usePumukit1 = false;
    /**
     * @var LoggerInterface
     */
    private $logger;
    private $syncStatus;
    private $dryRun;

    protected function configure()
    {
        $this
            ->setName('youtube:delete')
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        if ($this->syncStatus) {
            $status = [MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN];
        } else {
            $status = [MultimediaObject::STATUS_PUBLISHED];
        }
        $notPublishedMms = $this->getMultimediaObjectsInYoutubeWithoutStatus($publishedYoutubeIds, $status);
        if (0 != count($notPublishedMms) && !$this->dryRun) {
            $output->writeln('Removing '.count($notPublishedMms).' object(s) with status not published');
            $this->deleteVideosFromYoutube($notPublishedMms, $output);
        } else {
            $state = 'Not published multimedia objects';
            $this->showMultimediaObjects($output, $state, $notPublishedMms);
        }
        $arrayPubTags = $this->getContainer()->getParameter('pumukit_youtube.pub_channels_tags');
        foreach ($arrayPubTags as $tagCode) {
            $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
            $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
            // TODO When tag IMPORTANT is defined as child of PUBLICATION DECISION Tag
            $notCorrectTagMms = $this->getMultimediaObjectsInYoutubeWithoutTagCode($publishedYoutubeIds, $tagCode);
            if (0 != count($notCorrectTagMms) && !$this->dryRun) {
                $output->writeln('Removing '.count($notCorrectTagMms).' object(s) w/o tag '.$tagCode);
                $this->deleteVideosFromYoutube($notCorrectTagMms, $output);
            } else {
                $state = 'Not correct tags multimedia objects';
                $this->showMultimediaObjects($output, $state, $notCorrectTagMms);
            }
        }
        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        $notPublicMms = $this->getMultimediaObjectsInYoutubeWithoutEmbeddedBroadcast($publishedYoutubeIds, 'public');
        if (0 != count($notPublicMms) && !$this->dryRun) {
            $output->writeln('Removing '.count($notPublicMms).' object(s) with broadcast not public');
            $this->deleteVideosFromYoutube($notPublicMms, $output);
        } else {
            $state = 'Not public multimedia objects';
            $this->showMultimediaObjects($output, $state, $notPublicMms);
        }
        $orphanYoutubes = $this->youtubeRepo->findBy(['status' => Youtube::STATUS_TO_DELETE]);
        if (0 != count($orphanYoutubes) && !$this->dryRun) {
            $output->writeln('Removing '.count($orphanYoutubes).' orphanYoutube(s) ');
            $this->deleteOrphanVideosFromYoutube($orphanYoutubes, $output);
        } else {
            $state = 'Orphan youtube documents';
            $this->showYoutubeMultimediaObjects($output, $state, $orphanYoutubes);
        }
        if (!$this->dryRun) {
            $this->checkResultsAndSendEmail();
        }
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->tagRepo = $this->dm->getRepository(Tag::class);
        $this->youtubeRepo = $this->dm->getRepository(Youtube::class);
        $this->syncStatus = $this->getContainer()->getParameter('pumukit_youtube.sync_status');
        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');
        $this->tagService = $this->getContainer()->get('pumukitschema.tag');
        $this->okRemoved = [];
        $this->failedRemoved = [];
        $this->errors = [];
        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
        $this->usePumukit1 = $input->getOption('use-pmk1');
        $this->dryRun = (true === $input->getOption('dry-run'));
    }

    private function deleteVideosFromYoutube(iterable $mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                    .'] Started removing video from Youtube of MultimediaObject with id "'
                    .$mm->getId().'"';
                $this->logger->info($infoLog);
                $output->writeln($infoLog);
                $outDelete = $this->youtubeService->delete($mm);
                if (0 !== $outDelete) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                        .'] Unknown error in the removal from Youtube of MultimediaObject with id "'
                        .$mm->getId().'": '.$outDelete;
                    $this->logger->error($errorLog);
                    $output->writeln($errorLog);
                    $this->failedRemoved[] = $mm;
                    $this->errors[] = $errorLog;

                    continue;
                }
                $this->okRemoved[] = $mm;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                    .'] Removal of video from MultimediaObject with id "'.$mm->getId()
                    .'" has failed. '.$e->getMessage();
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $mm;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function deleteOrphanVideosFromYoutube(iterable $orphanYoutubes, OutputInterface $output)
    {
        foreach ($orphanYoutubes as $youtube) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                    .'] Started removing orphan video from Youtube with id "'
                    .$youtube->getId().'"';
                $this->logger->info($infoLog);
                $output->writeln($infoLog);
                $outDelete = $this->youtubeService->deleteOrphan($youtube);
                if (0 !== $outDelete) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                        .'] Unknown error in the removal from Youtube id "'
                        .$youtube->getId().'": '.$outDelete;
                    $this->logger->error($errorLog);
                    $output->writeln($errorLog);
                    $this->failedRemoved[] = $youtube;
                    $this->errors[] = $errorLog;

                    continue;
                }
                $youtubeTag = $this->dm->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
                $multimediaObject = $this->dm->getRepository(MultimediaObject::class)->findOneBy(['_id' => new \MongoId($youtube->getMultimediaObjectId())]);
                if ($multimediaObject) {
                    foreach ($multimediaObject->getTags() as $embeddedTag) {
                        if ($embeddedTag->isChildOf($youtubeTag)) {
                            $tag = $this->dm->getRepository(Tag::class)->findOneBy(['_id' => new \MongoId($embeddedTag->getId())]);
                            $youtube->setYoutubeAccount($tag->getProperty('login'));
                            $youtube->setStatus(Youtube::STATUS_UPLOADING);
                            $multimediaObject->removeProperty('youtube');
                            $multimediaObject->removeProperty('youtubeurl');
                            $this->dm->flush();
                        }
                    }
                }
                $this->okRemoved[] = $youtube;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                    .'] Removal of video from Youtube with id "'.$youtube->getId()
                    .'" has failed. '.$e->getMessage();
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $youtube;
                $this->errors[] = $e->getMessage();
            }
        }
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

    private function getMultimediaObjectsInYoutubeWithoutTagCode(array $youtubeIds, $tagCode)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('tags.cod')->notEqual($tagCode)
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
        $qb = $this->dm->getRepository(MultimediaObject::class)
            ->createQueryBuilder()
            ->field('properties.youtube')->in($youtubeIds)
            ->field('properties.origin')->notEqual('youtube');
        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb;
    }

    /**
     * @throws \Exception
     */
    private function checkResultsAndSendEmail()
    {
        $youtubeTag = $this->dm->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if (null !== $youtubeTag) {
            foreach ($this->okRemoved as $mm) {
                if ($mm instanceof MultimediaObject) {
                    $youtubeDocument = $this->dm->getRepository(Youtube::class)->findOneBy(['status' => Youtube::STATUS_REMOVED]);
                    if ($mm->containsTagWithCod(Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE) && $youtubeDocument) {
                        $this->tagService->removeTagFromMultimediaObject($mm, $youtubeTag->getId(), false);
                    }
                }
            }
            $this->dm->flush();
        }
        if (!empty($this->okRemoved) || !empty($this->failedRemoved)) {
            $this->youtubeService->sendEmail('remove', $this->okRemoved, $this->failedRemoved, $this->errors);
        }
    }

    /**
     * @param string $state
     * @param mixed  $multimediaObjects
     */
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

    /**
     * @param string $state
     * @param array  $youtubeDocuments
     */
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
    }
}
