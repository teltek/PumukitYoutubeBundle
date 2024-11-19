<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoListResponse;
use Pumukit\CoreBundle\Services\i18nService;
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

final class ImportLivesFromYouTubeChannelCommand extends Command
{
    public const YOUTUBE_STATUS_MAPPING = [
        'public' => MultimediaObject::STATUS_PUBLISHED,
        'unlisted' => MultimediaObject::STATUS_HIDDEN,
        'private' => MultimediaObject::STATUS_BLOCKED,
    ];

    private DocumentManager $documentManager;
    private GoogleAccountService $googleAccountService;
    private FactoryService $factoryService;
    private i18nService $i18nService;

    private TagService $tagService;

    private MultimediaObjectPicService $multimediaObjectPicService;

    private PlaylistListService $playlistListService;
    private string $tempDir;
    private string $channelId;
    private $youtubeErrors = [];

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        FactoryService $factoryService,
        i18nService $i18nService,
        TagService $tagService,
        MultimediaObjectPicService $multimediaObjectPicService,
        PlaylistListService $playlistListService,
        string $tempDir
    ) {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->factoryService = $factoryService;
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
            ->setName('pumukit:youtube:import:lives:from:channel')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel ID')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'limit')
            ->setDescription('Import all lives from Youtube channel')
            ->setHelp(
                <<<'EOT'

Import all lives from Youtube channel

Limit is optional to test the command.

Usage: php bin/console pumukit:youtube:import:lives:from:channel --account={ACCOUNT} --channel={CHANNEL_ID} --limit={LIMIT}

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

        $this->defaultSeries();

        $nextPageToken = null;
        $count = 0;
        $queryParams = [
            'channelId' => $this->channelId,
            'eventType' => 'completed',
            'maxResults' => 50,
            'type' => 'video',
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
                } catch (\Exception $exception) {
                    $this->youtubeErrors[] = 'YouTube ERROR: '.$exception->getMessage().' - Video ID: '.$videoId;

                    continue;
                }

                if (0 == $count % 50) {
                    $this->documentManager->flush();
                }
            }
        } while (null !== $nextPageToken);

        $this->documentManager->flush();
        $this->documentManager->clear();

        $progressBar->finish();
        $output->writeln(' ');

        foreach ($this->youtubeErrors as $error) {
            $output->writeln($error);
        }

        return 0;
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
            'properties.youtube_import_type' => 'channel_lives',
        ]);

        if ($series instanceof Series) {
            return $series;
        }

        $text = $this->i18nService->generateI18nText('Live Channel '.$this->channelId);
        $series = $this->factoryService->createSeries(null, $text);
        $series->setProperty('youtube_import_type', 'channel_lives');
        $series->setProperty('youtube_import_id', $this->channelId);
        $this->documentManager->flush();

        return $series;
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

        if (!$youtubeInfo) {
            $this->youtubeErrors[] = 'YouTube info not found for video ID '.$multimediaObject->getId();

            throw new \Exception('Snippet not found for MultimediaObject '.$multimediaObject->getId());
        }

        $text = $this->i18nService->generateI18nText($youtubeInfo->snippet->title);
        $multimediaObject->setI18nTitle($text);
        $text = $this->i18nService->generateI18nText($youtubeInfo->snippet->description);
        $multimediaObject->setI18nDescription($text);
        $multimediaObject->setProperty('youtube_import_raw', $youtubeInfo);
        $multimediaObject->setProperty('youtube_import_id', $youtubeInfo->id);
        $multimediaObject->setProperty('youtube_import_type', 'live');
        $multimediaObject->setProperty('youtube_import_channel', $this->channelId);

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
        return $this->defaultSeries();
    }
}
