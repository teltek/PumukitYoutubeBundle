<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\ChannelListResponse;
use Google\Service\YouTube\Playlist;
use Pumukit\CoreBundle\Services\i18nService;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\FactoryService;
use Pumukit\SchemaBundle\Services\SeriesPicService;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportPlaylistFromYouTubeChannel extends Command
{
    private DocumentManager $documentManager;
    private GoogleAccountService $googleAccountService;
    private FactoryService $factoryService;

    private i18nService $i18nService;
    private SeriesPicService $seriesPicService;
    private string $picDir;
    private string $channelId;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        FactoryService $factoryService,
        i18nService $i18nService,
        SeriesPicService $seriesPicService,
        string $picDir,
    ) {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->factoryService = $factoryService;
        $this->i18nService = $i18nService;
        $this->seriesPicService = $seriesPicService;
        $this->picDir = $picDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:import:playlist:from:channel')
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
            ->setDescription('Import playlist from YouTube to create series.')
            ->setHelp(
                <<<'EOT'

Create series on PuMuKIT based on YouTube playlist.

1 Series for each playlist and 1 default series for channel.

Usage: php bin/console pumukit:youtube:import:playlist:from:channel --account={ACCOUNT} --channel={CHANNEL_ID}

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

        $this->createDefaultSeriesForChannel($service);

        $nextPageToken = null;
        $count = 0;
        $queryParams = [
            'channelId' => $this->channelId,
            'maxResults' => 50,
        ];

        $response = $service->playlists->listPlaylists('snippet', $queryParams);

        $progressBar = new ProgressBar($output, $response->pageInfo->getTotalResults());
        $progressBar->start();
        do {
            if (null !== $nextPageToken) {
                $queryParams['pageToken'] = $nextPageToken;
            }

            $response = $service->playlists->listPlaylists('snippet', $queryParams);
            $nextPageToken = $response->getNextPageToken();
            foreach ($response->getItems() as $item) {
                $progressBar->advance();

                try {
                    $series = $this->ensureSeriesExists($item->id, $item->snippet->title);
                    $series = $this->autocompleteSeriesMetadata($series, $item);
                } catch (\Exception $exception) {
                    $output->writeln('There was error creating series by playlist: '.$item->snippet->title.'('.$item->id.')');

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
        $channels = $this->channelInfo($channel, $service);

        return $channels->getItems()[0]->getId();
    }

    private function channelInfo(string $channel, \Google_Service_YouTube $service): ChannelListResponse
    {
        $queryParams = ['id' => $channel];

        return $service->channels->listChannels('snippet', $queryParams);
    }

    private function ensureSeriesExists(string $id, string $text): Series
    {
        $series = $this->documentManager->getRepository(Series::class)->findOneBy([
            'properties.youtube_import_id' => $id,
        ]);

        if ($series instanceof Series) {
            return $series;
        }

        $text = $this->i18nService->generateI18nText($text);

        return $this->factoryService->createSeries(null, $text);
    }

    private function autocompleteSeriesMetadata(Series $series, Playlist $item): Series
    {
        $series->setProperty('youtube_import_id', $item->id);
        $series->setProperty('youtube_import_raw', json_encode($item->snippet));
        $series->setProperty('youtube_import_type', 'playlist');
        $series->setProperty('youtube_import_playlist_channel', $this->channelId);

        $series->setI18nTitle($this->i18nService->generateI18nText($item->snippet->title));
        $series->setI18nDescription($this->i18nService->generateI18nText($item->snippet->description));
        $series->setPublicDate(new \DateTime($item->snippet->publishedAt));

        if (null !== $item->snippet->thumbnails->getMaxres()) {
            $filePath = $this->downloadThumbnail($item, $series);
            $this->seriesPicService->addPicFromPath($series, $filePath);
        }

        return $series;
    }

    private function downloadThumbnail(Playlist $item, Series $series): string
    {
        $seriesStoragePath = $this->picDir.'/series/'.$series->getId().'/';
        if (!is_dir($seriesStoragePath)) {
            mkdir($seriesStoragePath, 0775, true);
        }

        $fileName = basename(parse_url($item->snippet->thumbnails->getMaxres()->getUrl(), PHP_URL_PATH));
        $path = $seriesStoragePath.$fileName;

        $content = file_get_contents($item->snippet->thumbnails->getMaxres()->getUrl());
        file_put_contents($path, $content);

        return $path;
    }

    private function createDefaultSeriesForChannel(\Google_Service_YouTube $service): void
    {
        $channelInfo = $this->channelInfo($this->channelId, $service);
        $series = $this->ensureSeriesExists(
            $channelInfo->getItems()[0]->getId(),
            $channelInfo->getItems()[0]->getSnippet()->getTitle()
        );

        $channelData = $channelInfo->getItems()[0];
        $series->setProperty('youtube_import_id', $channelData->id);
        $series->setProperty('youtube_import_raw', json_encode($channelData));
        $series->setProperty('youtube_import_type', 'channel');

        $series->setI18nTitle($this->i18nService->generateI18nText('Huerfanos'));
        $series->setI18nDescription($this->i18nService->generateI18nText($channelData->snippet->localized->description));
        $series->setPublicDate(new \DateTime($channelData->snippet->publishedAt));

        $this->documentManager->flush();
    }
}
