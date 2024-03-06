<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoListResponse;
use Pumukit\CoreBundle\Services\i18nService;
use Pumukit\CoreBundle\Utils\FinderUtils;
use Pumukit\EncoderBundle\Services\JobService;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\MultimediaObjectPicService;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\WebTVBundle\PumukitWebTVBundle;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Pumukit\YoutubeBundle\Services\PlaylistListService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportVideosFromYouTubeChannel extends Command
{
    public const YOUTUBE_STATUS_MAPPING = [
        'public' => MultimediaObject::STATUS_PUBLISHED,
        'hidden' => MultimediaObject::STATUS_HIDDEN,
    ];
    public const DEFAULT_PROFILE_ENCODER = 'broadcastable_master';
    private DocumentManager $documentManager;
    private GoogleAccountService $googleAccountService;
    private FactoryService $factoryService;
    private JobService $jobService;
    private i18nService $i18nService;

    private TagService $tagService;

    private MultimediaObjectPicService $multimediaObjectPicService;

    private PlaylistListService $playlistListService;
    private string $tempDir;
    private string $channelId;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        FactoryService $factoryService,
        JobService $jobService,
        i18nService $i18nService,
        TagService $tagService,
        MultimediaObjectPicService $multimediaObjectPicService,
        PlaylistListService $playlistListService,
        string $tempDir
    ) {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->factoryService = $factoryService;
        $this->jobService = $jobService;
        $this->i18nService = $i18nService;
        $this->tagService = $tagService;
        $this->multimediaObjectPicService = $multimediaObjectPicService;
        $this->playlistListService = $playlistListService;
        $this->tempDir = $tempDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:import:videos:from:channel')
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

Limit is optional to test the command.

Usage: php bin/console pumukit:youtube:import:videos:from:channel --account={ACCOUNT} --channel={CHANNEL_ID} --limit={LIMIT}

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('channel');

        $youtubeAccount = $this->ensureYouTubeAccountExists($input);

        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);
        $this->channelId = $this->channelId($channel, $service);

        $nextPageToken = null;
        $count = 0;
        $queryParams = [
            'type' => 'video',
            'forMine' => true,
            'maxResults' => 50,
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

            $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);
            $response = $service->search->listSearch('snippet', $queryParams);
            $nextPageToken = $response->getNextPageToken();
            foreach ($response->getItems() as $item) {
                $progressBar->advance();
                if (null !== $input->getOption('limit') && $count >= $input->getOption('limit')) {
                    break;
                }
                ++$count;
                $videoId = $item->getId()->getVideoId();

                try {
                    $videoInfo = $this->videoInfo($service, $videoId);
                    $series = $this->obtainSeriesToSave($service, $videoId);

                    $multimediaObject = $this->ensureMultimediaObjectExists($series, $videoId);
                    $multimediaObject = $this->autocompleteMultimediaObjectMetadata($multimediaObject, $videoInfo);

                    // Download and add maxRes PIC.

                    $this->addJob($multimediaObject, $videoId);
                } catch (\Exception $exception) {
                    $output->writeln('There was error downloaded video with title '.$item->snippet->title.'  and id '.$videoId);
                    $output->writeln($exception->getMessage());

                    continue;
                }

                $this->mongoDBFlush($count);
            }
        } while (null !== $nextPageToken);

        $this->documentManager->flush();
        $this->documentManager->clear();

        $progressBar->finish();
        $output->writeln(' ');

        return 0;
    }

    private function mongoDBFlush(int $count): void
    {
        if (0 == $count % 50) {
            $this->documentManager->flush();
            $this->documentManager->clear();
        }
    }

    private function ensureYouTubeAccountExists(InputInterface $input): Tag
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

    private function defaultSeries(): Series
    {
        $series = $this->documentManager->getRepository(Series::class)->findOneBy([
            'properties.youtube_import_id' => $this->channelId,
            'properties.youtube_import_type' => 'channel',
        ]);

        if ($series instanceof Series) {
            return $series;
        }

        throw new \Exception('Default series for import not found. Execute pumukit:youtube:import:playlist:from:channel first.');
    }

    private function ensureMultimediaObjectExists(Series $series, string $videoId): MultimediaObject
    {
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
            'properties.youtube_import_id' => $videoId,
        ]);

        if ($multimediaObject instanceof MultimediaObject) {
            return $multimediaObject;
        }

        return $this->factoryService->createMultimediaObject($series);
    }

    private function autocompleteMultimediaObjectMetadata(MultimediaObject $multimediaObject, VideoListResponse $videoInfo): MultimediaObject
    {
        $youtubeInfo = $videoInfo->getItems()[0];

        $text = $this->i18nService->generateI18nText($youtubeInfo->snippet->title);
        $multimediaObject->setI18nTitle($text);
        $text = $this->i18nService->generateI18nText($youtubeInfo->snippet->description);
        $multimediaObject->setI18nDescription($text);
        $multimediaObject->setProperty('youtube_import_raw', $youtubeInfo);
        $multimediaObject->setProperty('youtube_import_id', $youtubeInfo->id);
        $multimediaObject->setProperty('youtube_import_type', 'video');
        $multimediaObject->setProperty('youtube_import_channel', $youtubeInfo->id);

        $multimediaObject->setPublicDate(new \DateTime());
        $multimediaObject->setRecordDate(new \DateTime($youtubeInfo->snippet->publishedAt));
        $multimediaObject->setStatus($this->convertYouTubeStatus($youtubeInfo->status->privacyStatus));
        $this->addBasicTags($multimediaObject);
        $multimediaObject = $this->addKeywords($multimediaObject, $youtubeInfo);

        if (null !== $youtubeInfo->snippet->thumbnails->getMaxres()) {
            $filePath = $this->downloadThumbnail($youtubeInfo, $multimediaObject);
            $this->multimediaObjectPicService->addPicFromPath($multimediaObject, $filePath);
        }

        return $multimediaObject;
    }

    private function addJob(MultimediaObject $multimediaObject, string $youtubeId): MultimediaObject
    {
        $path = $this->tempDir.'/'.$this->channelId.'/';
        $trackUrl = FinderUtils::findFilePathname($path, $youtubeId);

        if (!$trackUrl) {
            return $multimediaObject;
        }

        $profile = self::DEFAULT_PROFILE_ENCODER;
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

    private function videoInfo(\Google_Service_YouTube $service, string $videoId): VideoListResponse
    {
        return $service->videos->listVideos('snippet, status', ['id' => $videoId]);
    }

    private function convertYouTubeStatus(string $status): int
    {
        return self::YOUTUBE_STATUS_MAPPING[strtolower($status)] ?? MultimediaObject::STATUS_HIDDEN;
    }

    private function addBasicTags(MultimediaObject $multimediaObject): void
    {
        $this->tagService->addTagByCodToMultimediaObject($multimediaObject, PumukitWebTVBundle::WEB_TV_TAG);
    }

    private function addKeywords(MultimediaObject $multimediaObject, Video $video): MultimediaObject
    {
        if (null === $video->snippet->tags) {
            return $multimediaObject;
        }

        foreach ($video->snippet->tags as $tag) {
            $multimediaObject->addKeyword($tag);
        }

        return $multimediaObject;
    }

    private function downloadThumbnail(Video $video, MultimediaObject $multimediaObject): string
    {
        $multimediaObjectStoragePath = $this->multimediaObjectPicService->getTargetPath($multimediaObject).'/';
        if (!is_dir($multimediaObjectStoragePath)) {
            mkdir($multimediaObjectStoragePath, 0775, true);
        }

        $fileName = basename(parse_url($video->snippet->thumbnails->getMaxres()->getUrl(), PHP_URL_PATH));
        $path = $multimediaObjectStoragePath.$fileName;

        $content = file_get_contents($video->snippet->thumbnails->getMaxres()->getUrl());
        file_put_contents($path, $content);

        return $path;
    }

    private function obtainSeriesToSave(\Google_Service_YouTube $service, string $videoId): Series
    {
        $playlists = $this->documentManager->getRepository(Series::class)->findBy([
            'properties.youtube_import_type' => 'playlist',
        ]);

        foreach ($playlists as $playlist) {
            $response = $service->playlistItems->listPlaylistItems('snippet', [
                'playlistId' => $playlist->getProperty('youtube_import_id'),
                'videoId' => $videoId,
            ]);

            if (0 === count($response->getItems())) {
                continue;
            }

            if (1 === count($response->getItems())) {
                return $playlist;
            }
        }

        return $this->defaultSeries();
    }
}
