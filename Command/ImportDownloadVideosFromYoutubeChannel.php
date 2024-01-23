<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YouTube\DownloadOptions;
use YouTube\Exception\YouTubeException;
use YouTube\Models\StreamFormat;
use YouTube\Utils\Utils;
use YouTube\YouTubeDownloader;

final class ImportDownloadVideosFromYoutubeChannel extends Command
{
    public const BASE_URL_YOUTUBE_VIDEO = 'https://www.youtube.com/watch?v=';

    private DocumentManager $documentManager;
    private GoogleAccountService $googleAccountService;
    private string $tempDir;

    public function __construct(DocumentManager $documentManager, GoogleAccountService $googleAccountService, string $tempDir)
    {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
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

Limit is optional to test the command. If you don't set it, all videos will be downloaded.

Usage: php bin/console pumukit:youtube:import:videos:from:channel --account={ACCOUNT} --channel={CHANNEL_ID} --limit={LIMIT}

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
                    $youtubeURL = self::BASE_URL_YOUTUBE_VIDEO.$videoId;
                    $downloadOptions = $youtubeDownloader->getDownloadLinks($youtubeURL);

                    if (empty($downloadOptions->getAllFormats())) {
                        $output->writeln('URL: '.$youtubeURL.' no formats found.');

                        continue;
                    }

                    $url = $this->selectBestStreamFormat($downloadOptions);

                    try {
                        $this->moveFileToStorage($item, $url, $downloadOptions, $channelId);
                    } catch (\Exception $exception) {
                        $output->writeln('Error moving file to storage: '.$exception->getMessage());
                    }
                } catch (YouTubeException $e) {
                    $output->writeln('There was error downloaded video with title '.$item->snippet->title.'  and id '.$videoId);

                    continue;
                }
            }
        } while (null !== $nextPageToken);

        $progressBar->finish();
        $output->writeln(' ');

        return 0;
    }

    private function selectBestStreamFormat(DownloadOptions $downloadOptions): ?StreamFormat
    {
        $quality2160p = Utils::arrayFilterReset($downloadOptions->getAllFormats(), function ($format) {
            return str_starts_with($format->mimeType, 'video') && '2160p' === $format->qualityLabel;
        });

        if (!empty($quality2160p)) {
            return $quality2160p[0];
        }

        $quality1440p = Utils::arrayFilterReset($downloadOptions->getAllFormats(), function ($format) {
            return str_starts_with($format->mimeType, 'video') && '1440p' === $format->qualityLabel;
        });

        if (!empty($quality1440p)) {
            return $quality1440p[0];
        }

        $quality1080p = Utils::arrayFilterReset($downloadOptions->getAllFormats(), function ($format) {
            return str_starts_with($format->mimeType, 'video') && !empty($format->audioQuality) && '1080p' === $format->qualityLabel;
        });

        if (!empty($quality1080p)) {
            return $quality1080p[0];
        }

        $quality720p = Utils::arrayFilterReset($downloadOptions->getAllFormats(), function ($format) {
            return str_starts_with($format->mimeType, 'video') && !empty($format->audioQuality) && '720p' === $format->qualityLabel;
        });

        if (!empty($quality720p)) {
            return $quality720p[0];
        }

        $quality360p = Utils::arrayFilterReset($downloadOptions->getAllFormats(), function ($format) {
            return str_starts_with($format->mimeType, 'video') && !empty($format->audioQuality) && '360p' === $format->qualityLabel;
        });

        if (!empty($quality360p)) {
            return $quality360p[0];
        }

        $quality240p = Utils::arrayFilterReset($downloadOptions->getAllFormats(), function ($format) {
            return str_starts_with($format->mimeType, 'video') && !empty($format->audioQuality) && '240p' === $format->qualityLabel;
        });

        if (!empty($quality240p)) {
            return $quality240p[0];
        }

        return null;
    }

    private function moveFileToStorage($item, $url, DownloadOptions $downloadOptions, string $channelId): void
    {
        $videoId = $item->getId()->getVideoId();
        $mimeType = explode('video/', $this->selectBestStreamFormat($downloadOptions)->getCleanMimeType())[1];
        $this->createChannelDir($channelId);
        $file = $this->tempDir.'/'.$channelId.'/'.$videoId.'.'.$mimeType;

        if (file_exists($file)) {
            return;
        }

        $content = file_get_contents($url->url);

        file_put_contents($file, $content);
    }

    private function createChannelDir(string $channelId): void
    {
        if (!is_dir($this->tempDir.'/'.$channelId)) {
            mkdir($this->tempDir.'/'.$channelId, 0775, true);
        }
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
}
