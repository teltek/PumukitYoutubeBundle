<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\Services\VideoDataValidationService;
use Pumukit\YoutubeBundle\Services\VideoListService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VideoLinkCommand extends Command
{
    private $documentManager;
    private $videoListService;
    private $videoDataValidationService;
    private $tagService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        VideoListService $videoListService,
        VideoDataValidationService $videoDataValidationService,
        TagService $tagService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->videoListService = $videoListService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->tagService = $tagService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:video:link')
            ->addOption('youtube-id', null, InputOption::VALUE_REQUIRED, 'YouTube video ID')
            ->addOption('mmobj-id', null, InputOption::VALUE_REQUIRED, 'MultimediaObject ID to link the video to')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'YouTube account login (matches the account Tag properties.login)')
            ->addOption('skip-fetch', null, InputOption::VALUE_NONE, 'Skip YouTube API verification (assume PUBLISHED, uploadDate=now). Useful when account credentials are unavailable or when bulk-normalizing.')
            ->setDescription('Link an externally uploaded YouTube video to an existing MultimediaObject')
            ->setHelp(
                <<<'EOT'
Link a YouTube video that was uploaded directly to YouTube (without going
through PuMuKIT) to an existing MultimediaObject. The command creates the
Youtube document, assigns the YouTube account Tag and the publication channel
Tag (PUCHYOUTUBE) to the MultimediaObject, and stamps the relevant properties.

This is the legitimate counterpart of pumukit:youtube:video:review:assign,
which is reserved for repairing Youtube documents stuck in STATUS_TO_REVIEW.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $youtubeId = $input->getOption('youtube-id');
        $mmObjId = $input->getOption('mmobj-id');
        $accountLogin = $input->getOption('account');
        $skipFetch = (bool) $input->getOption('skip-fetch');

        if (!$youtubeId || !$mmObjId || !$accountLogin) {
            $output->writeln('<error>--youtube-id, --mmobj-id and --account are required.</error>');

            return 1;
        }

        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
            '_id' => new ObjectId($mmObjId),
        ]);
        if (!$multimediaObject instanceof MultimediaObject) {
            $output->writeln(sprintf('<error>MultimediaObject %s not found.</error>', $mmObjId));

            return 1;
        }

        $existingForMmObj = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $mmObjId,
        ]);
        if ($existingForMmObj instanceof Youtube) {
            $output->writeln(sprintf(
                '<error>MultimediaObject %s already has a Youtube document (id=%s, status=%s). Use pumukit:youtube:video:review:assign to repair it instead.</error>',
                $mmObjId,
                $existingForMmObj->getId(),
                $existingForMmObj->getStatusText()
            ));

            return 1;
        }

        $existingForVideo = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'youtubeId' => $youtubeId,
        ]);
        if ($existingForVideo instanceof Youtube) {
            $output->writeln(sprintf(
                '<error>YouTube video %s is already linked to MultimediaObject %s.</error>',
                $youtubeId,
                $existingForVideo->getMultimediaObjectId()
            ));

            return 1;
        }

        $accountTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $accountLogin,
        ]);
        if (!$accountTag instanceof Tag) {
            $output->writeln(sprintf('<error>No YouTube account Tag found with login "%s".</error>', $accountLogin));

            return 1;
        }

        $video = null;
        if (!$skipFetch) {
            try {
                $video = $this->videoListService->fetchVideoDetails($accountTag, $youtubeId);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>YouTube API error fetching video %s: %s</error>', $youtubeId, $e->getMessage()));
                $output->writeln('<comment>Re-run with --skip-fetch to bypass the YouTube API verification.</comment>');
                $this->logger->error(sprintf('[YouTube] video:link API error for %s: %s', $youtubeId, $e->getMessage()));

                return 1;
            }

            if (null === $video) {
                $output->writeln(sprintf('<error>YouTube video %s not found on account "%s".</error>', $youtubeId, $accountLogin));

                return 1;
            }

            $expectedTitle = $this->videoDataValidationService->getTitleForYoutube($multimediaObject);
            $actualTitle = $video->getSnippet() ? (string) $video->getSnippet()->getTitle() : '';
            if ('' !== $actualTitle && $expectedTitle !== $actualTitle) {
                $output->writeln(sprintf(
                    '<comment>Note: title differs.</comment> MultimediaObject: "%s" / YouTube: "%s"',
                    $expectedTitle,
                    $actualTitle
                ));
            }
        } else {
            $output->writeln('<comment>--skip-fetch: skipping YouTube API verification. Assuming status=PUBLISHED, uploadDate=now.</comment>');
        }

        $mappedStatus = Youtube::STATUS_PUBLISHED;
        if (null !== $video && $video->getStatus()) {
            $uploadStatus = (string) $video->getStatus()->getUploadStatus();
            if ('' !== $uploadStatus) {
                $mappedStatus = $this->videoListService->mapYoutubeUploadStatus($uploadStatus);
            }
        }

        $youtube = new Youtube();
        $youtube->setMultimediaObjectId($mmObjId);
        $youtube->setYoutubeId($youtubeId);
        $youtube->setYoutubeAccount($accountLogin);
        $youtube->setLink('https://www.youtube.com/watch?v='.$youtubeId);
        $youtube->setEmbed('<iframe width="853" height="480" src="https://www.youtube.com/embed/'.$youtubeId.'" allowfullscreen></iframe>');
        $youtube->setStatus($mappedStatus);
        $youtube->setSyncMetadataDate(new \DateTime('now'));

        if (null !== $video && $video->getSnippet() && $video->getSnippet()->getPublishedAt()) {
            try {
                $youtube->setUploadDate(new \DateTime($video->getSnippet()->getPublishedAt()));
            } catch (\Exception $e) {
                // keep constructor default
            }
        }

        $this->documentManager->persist($youtube);
        $this->documentManager->flush();

        if (!$multimediaObject->containsTag($accountTag)) {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $accountTag->getId());
        }

        $puchYoutubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE,
        ]);
        if (!$puchYoutubeTag instanceof Tag) {
            $output->writeln(sprintf(
                '<error>Publication channel Tag %s not found. Run pumukit:youtube:init:tags first.</error>',
                PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE
            ));

            return 1;
        }
        if (!$multimediaObject->containsTagWithCod(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE)) {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $puchYoutubeTag->getId());
        }

        $multimediaObject->setProperty('youtube', $youtube->getId());
        $multimediaObject->setProperty('youtubeurl', $youtube->getLink());

        $this->documentManager->flush();

        $output->writeln(sprintf(
            '<info>Linked MultimediaObject %s to YouTube video %s on account "%s" (status: %s).</info>',
            $mmObjId,
            $youtubeId,
            $accountLogin,
            $youtube->getStatusText()
        ));

        return 0;
    }
}
