<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\Services\VideoDeleteService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VideoDeleteCommand extends Command
{
    private $documentManager;
    private $youtubeConfigurationService;
    private $videoDeleteService;
    private $usePumukit1 = false;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        VideoDeleteService $videoDeleteService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoDeleteService = $videoDeleteService;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:video:delete')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
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

        // Use case:
        // Videos with YouTube document on PUBLISHED but status on PuMuKIT not allowed to be published
        // Ex: sync_status false and status hidden or blocked.
        $notPublishedMms = $this->notPublishedMultimediaObjects();
        $infoLog = '[YouTube] Deleting not published videos  '.(is_countable($notPublishedMms) ? count($notPublishedMms) : 0).' on YouTube';
        $this->logger->info($infoLog);
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
        $infoLog = '[YouTube] Deleting videos published on YouTube but without account/configuration to be on YouTube:  '.(is_countable($notCorrectTagMms) ? count($notCorrectTagMms) : 0);
        $this->logger->info($infoLog);
        $this->deleteVideosFromYoutube($notCorrectTagMms, $output);

        // Use case:
        // Videos published on YouTube but with EmbeddedBroadcast distinct of public
        $notPublicMms = $this->getMultimediaObjectsInYoutubeWithoutEmbeddedBroadcast($publishedYoutubeIds, 'public');
        $infoLog = '[YouTube] Deleting videos with EmbeddedBroadcast not public:  '.(is_countable($notPublicMms) ? count($notPublicMms) : 0);
        $this->logger->info($infoLog);
        $this->deleteVideosFromYoutube($notPublicMms, $output);

        // Use case:
        // Videos that was removed from PuMuKIT but have YouTube document.
        $orphanVideos = $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => Youtube::STATUS_TO_DELETE,
        ]);
        $infoLog = '[YouTube] Deleting orphan videos:  '.(is_countable($notPublicMms) ? count($notPublicMms) : 0);
        $this->logger->info($infoLog);
        $this->deleteOrphanVideosFromYoutube($orphanVideos, $output);

        return 0;
    }

    private function deleteVideosFromYoutube(iterable $multimediaObjects, OutputInterface $output): void
    {
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $result = $this->videoDeleteService->deleteVideoFromYouTubeByMultimediaObject($multimediaObject);
            } catch (\Exception $e) {
                $errorLog = sprintf('[YouTube] Remove video %s failed. Error: %s', $multimediaObject->getId(), $e->getMessage());
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
            }
        }
    }

    private function deleteOrphanVideosFromYoutube(iterable $youtubeDocuments, OutputInterface $output): void
    {
        foreach ($youtubeDocuments as $youtube) {
            try {
                $result = $this->videoDeleteService->deleteVideoFromYouTubeByYouTubeDocument($youtube);

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
            } catch (\Exception $e) {
                $errorLog = sprintf('[YouTube] Remove video assigned on YoutubeDocument %s failed. Error: %s', $youtube->getId(), $e->getMessage());
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
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
            ->field('properties.origin')->notEqual('youtube')
        ;
        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb;
    }
}
