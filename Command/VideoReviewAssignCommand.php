<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\VideoDataValidationService;
use Pumukit\YoutubeBundle\Services\VideoListService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VideoReviewAssignCommand extends Command
{
    private $documentManager;
    private $videoListService;
    private $videoDataValidationService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        VideoListService $videoListService,
        VideoDataValidationService $videoDataValidationService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->videoListService = $videoListService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:video:review:assign')
            ->addOption('youtube-id', null, InputOption::VALUE_REQUIRED, 'YouTube video ID to link')
            ->addOption('mmobj-id', null, InputOption::VALUE_REQUIRED, 'MultimediaObject ID owning the Youtube document in STATUS_TO_REVIEW')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip the STATUS_TO_REVIEW guard and reassign anyway')
            ->setDescription('Manually link a Youtube document (STATUS_TO_REVIEW) to a YouTube video')
            ->setHelp(
                <<<'EOT'
Resolve a Youtube document stuck in STATUS_TO_REVIEW (no youtubeId saved) by
manually pointing it at a YouTube video that you have located on the channel.

The command verifies the video exists on the account associated to the
MultimediaObject and is not already linked to another Youtube document. The
title is compared and a warning is emitted if it does not match, but the
operation is not aborted.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $youtubeId = $input->getOption('youtube-id');
        $mmObjId = $input->getOption('mmobj-id');
        $force = (bool) $input->getOption('force');

        if (!$youtubeId || !$mmObjId) {
            $output->writeln('<error>Both --youtube-id and --mmobj-id are required.</error>');

            return 1;
        }

        $youtube = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $mmObjId,
        ]);

        if (!$youtube instanceof Youtube) {
            $output->writeln(sprintf('<error>No Youtube document found for MultimediaObject %s.</error>', $mmObjId));

            return 1;
        }

        if (!$force && Youtube::STATUS_TO_REVIEW !== $youtube->getStatus()) {
            $output->writeln(sprintf(
                '<error>Youtube document for MultimediaObject %s is in status %d (%s), not STATUS_TO_REVIEW. Use --force to override.</error>',
                $mmObjId,
                $youtube->getStatus(),
                $youtube->getStatusText()
            ));

            return 1;
        }

        $existing = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'youtubeId' => $youtubeId,
        ]);
        if ($existing instanceof Youtube && $existing->getId() !== $youtube->getId()) {
            $output->writeln(sprintf(
                '<error>YouTube video %s is already linked to Youtube document %s (MultimediaObject %s).</error>',
                $youtubeId,
                $existing->getId(),
                $existing->getMultimediaObjectId()
            ));

            return 1;
        }

        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
            '_id' => new ObjectId($mmObjId),
        ]);
        if (!$multimediaObject instanceof MultimediaObject) {
            $output->writeln(sprintf('<error>MultimediaObject %s not found.</error>', $mmObjId));

            return 1;
        }

        $account = $this->resolveAccount($youtube);
        if (!$account instanceof Tag) {
            $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);
        }
        if (!$account instanceof Tag) {
            $output->writeln(sprintf('<error>Could not resolve a YouTube account for MultimediaObject %s.</error>', $mmObjId));

            return 1;
        }

        try {
            $video = $this->videoListService->fetchVideoDetails($account, $youtubeId);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>YouTube API error fetching video %s: %s</error>', $youtubeId, $e->getMessage()));
            $this->logger->error(sprintf('[YouTube] review:assign API error for %s: %s', $youtubeId, $e->getMessage()));

            return 1;
        }

        if (null === $video) {
            $output->writeln(sprintf('<error>YouTube video %s not found on account %s.</error>', $youtubeId, $account->getProperty('login')));

            return 1;
        }

        $expectedTitle = $this->videoDataValidationService->getTitleForYoutube($multimediaObject);
        $actualTitle = $video->getSnippet() ? (string) $video->getSnippet()->getTitle() : '';
        if ($expectedTitle !== $actualTitle) {
            $output->writeln(sprintf(
                '<comment>Warning: title mismatch.</comment> Expected: "%s" / YouTube: "%s"',
                $expectedTitle,
                $actualTitle
            ));
        }

        $uploadStatus = $video->getStatus() ? (string) $video->getStatus()->getUploadStatus() : '';
        $mappedStatus = $uploadStatus
            ? $this->videoListService->mapYoutubeUploadStatus($uploadStatus)
            : Youtube::STATUS_PROCESSING;

        $youtube->setYoutubeId($youtubeId);
        $youtube->setYoutubeAccount($account->getProperty('login'));
        $youtube->setLink('https://www.youtube.com/watch?v='.$youtubeId);
        $youtube->setEmbed('<iframe width="853" height="480" src="https://www.youtube.com/embed/'.$youtubeId.'" allowfullscreen></iframe>');
        $youtube->setStatus($mappedStatus);
        $youtube->setSyncMetadataDate(new \DateTime('now'));

        if ($video->getSnippet() && $video->getSnippet()->getPublishedAt()) {
            try {
                $youtube->setUploadDate(new \DateTime($video->getSnippet()->getPublishedAt()));
            } catch (\Exception $e) {
                // keep previous uploadDate
            }
        }

        if (Youtube::STATUS_TO_REVIEW !== $mappedStatus && Youtube::STATUS_ERROR !== $mappedStatus) {
            $youtube->removeError();
        }

        $multimediaObject->setProperty('youtube', $youtube->getId());
        $multimediaObject->setProperty('youtubeurl', $youtube->getLink());

        $this->documentManager->flush();

        $output->writeln(sprintf(
            '<info>Linked MultimediaObject %s to YouTube video %s (status: %s).</info>',
            $mmObjId,
            $youtubeId,
            $youtube->getStatusText()
        ));

        return 0;
    }

    private function resolveAccount(Youtube $youtube): ?Tag
    {
        if (!$youtube->getYoutubeAccount()) {
            return null;
        }

        return $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $youtube->getYoutubeAccount(),
        ]);
    }
}
