<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\CoreBundle\Utils\FinderUtils;
use Pumukit\EncoderBundle\Services\JobService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YouTube\DownloadOptions;
use YouTube\YouTubeDownloader;

final class GenerateMultimediaObjectByYouTubeAPICommand extends Command
{
    private DocumentManager $documentManager;
    private GoogleAccountService $googleAccountService;
    private FactoryService $factoryService;
    private JobService $jobService;
    private string $tempDir;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        FactoryService $factoryService,
        JobService $jobService,
        string $tempDir
    ) {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->factoryService = $factoryService;
        $this->jobService = $jobService;
        $this->tempDir = $tempDir;
        parent::__construct();
    }

    public function mongoDBFlush(int $count): void
    {
        if (0 == $count % 50) {
            $this->documentManager->flush();
            $this->documentManager->clear();
        }
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:generate:multimedia:from:api')
            ->addOption(
                'account',
                null,
                InputOption::VALUE_REQUIRED,
                'Account'
            )
            ->addOption(
                'channel',
                null,
                InputOption::VALUE_REQUIRED,
                'Channel ID'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'limit'
            )
            ->setDescription('Import all videos from Youtube channel')
            ->setHelp(
                <<<'EOT'
Import all videos from Youtube channel

Limit is optional to test the command. If you don't set it, all videos will be downloaded.

Usage: php bin/console pumukit:youtube:generate:multimedia:from:api --account={ACCOUNT} --channel={CHANNEL_ID} --limit={LIMIT}

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('channel');

        $youtubeAccount = $this->getYoutubeAccount($input);

        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);
        $channelId = $this->channelId($channel, $service);

        $nextPageToken = null;
        $count = 0;
        $queryParams = [
            'type' => 'video',
            'forMine' => true,
        ];

        $response = $service->search->listSearch('snippet', $queryParams);

        $progressBar = new ProgressBar($output, $response->pageInfo->getTotalResults());
        $progressBar->start();
        do {
            if (null !== $input->getOption('limit') && $count >= $input->getOption('limit')) {
                break;
            }

            if (null !== $nextPageToken) {
                $queryParams['pageToken'] = $nextPageToken;
            }

            $response = $service->search->listSearch('snippet', $queryParams);
            $nextPageToken = $response->getNextPageToken();
            foreach ($response->getItems() as $item) {
                $progressBar->advance();
                if (null !== $input->getOption('limit') && $count >= $input->getOption('limit')) {
                    break;
                }
                ++$count;
                $videoId = $item->getId()->getVideoId();
                $youtubeDownloader = new YouTubeDownloader();

                try {
                    $youtubeURL = 'https://www.youtube.com/watch?v='.$videoId;
                    $downloadOptions = $youtubeDownloader->getDownloadLinks($youtubeURL);

                    $series = $this->ensureSeriesExists($channelId);
                    $series = $this->autocompleteSeriesMetadata($series, $downloadOptions, $channelId);
                    $multimediaObject = $this->ensureMultimediaObjectExists($series, $videoId);
                    $multimediaObject = $this->autocompleteMultimediaObjectMetadata($multimediaObject, $downloadOptions);
                    $this->addJob($multimediaObject, $videoId, $channelId);
                } catch (\Exception $exception) {
                    $output->writeln('There was error downloaded video with title '.$item->snippet->title.'  and id '.$videoId);

                    continue;
                }

                $this->mongoDBFlush($count);
            }
        } while (null !== $nextPageToken);

        $this->mongoDBFlush($count);

        $progressBar->finish();
        $output->writeln(' ');

        return 0;
    }

    private function getYoutubeAccount(InputInterface $input): Tag
    {
        $youtubeAccount = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $input->getOption('account'),
        ]);

        if (!$youtubeAccount) {
            throw new \Exception('Account not found');
        }

        return $youtubeAccount;
    }

    private function channelId(string $channel, \Google_Service_YouTube $service): string
    {
        $queryParams = [
            'id' => $channel,
        ];

        $channels = $service->channels->listChannels('snippet', $queryParams);

        return $channels->getItems()[0]->getId();
    }

    private function ensureSeriesExists(string $channelId): Series
    {
        $series = $this->documentManager->getRepository(Series::class)->findOneBy([
            'properties.youtube_channel_id' => $channelId,
        ]);

        if ($series instanceof Series) {
            return $series;
        }

        $text = $this->generateTextWithLocales($channelId);

        return $this->factoryService->createSeries(null, $text);
    }

    private function generateTextWithLocales(string $channelId): array
    {
        $text = [];
        foreach ($this->factoryService->getLocales() as $locale) {
            $text[$locale] = $channelId;
        }

        return $text;
    }

    private function autocompleteSeriesMetadata(Series $series, DownloadOptions $downloadOptions, string $channelId): Series
    {
        $series->setProperty('youtube_channel_id', $channelId);

        return $series;
    }

    private function ensureMultimediaObjectExists(Series $series, string $videoId): MultimediaObject
    {
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
            'properties.youtube_video_id' => $videoId,
        ]);

        if ($multimediaObject instanceof MultimediaObject) {
            return $multimediaObject;
        }

        return $this->factoryService->createMultimediaObject($series);
    }

    private function autocompleteMultimediaObjectMetadata(MultimediaObject $multimediaObject, DownloadOptions $downloadOptions)
    {
        $youtubeInfo = $downloadOptions->getInfo();
        $text = $this->generateTextWithLocales($youtubeInfo->title);
        $multimediaObject->setI18nTitle($text);
        $text = $this->generateTextWithLocales($youtubeInfo->description);
        $multimediaObject->setI18nDescription($text);
        $multimediaObject->setProperty('youtube_metadata', $youtubeInfo);
        $multimediaObject->setProperty('youtube_video_id', $youtubeInfo->id);

        return $multimediaObject;
    }

    private function addJob(MultimediaObject $multimediaObject, string $youtubeId, string $channelId): MultimediaObject
    {
        $path = $this->tempDir.'/'.$channelId.'/';
        $trackUrl = FinderUtils::findFilePathname($path, $youtubeId);

        if (!$trackUrl) {
            return $multimediaObject;
        }

        $profile = 'broadcastable_master';
        $priority = 0;
        $language = null;

        return $this->jobService->createTrackFromInboxOnServer(
            $multimediaObject,
            $trackUrl,
            $profile,
            $priority,
            $language,
            $description = '',
            $initVars = [],
            $duration = 0,
            $flags = 0
        );
    }
}
