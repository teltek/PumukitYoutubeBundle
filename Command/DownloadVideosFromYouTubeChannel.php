<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\EncoderBundle\Document\Job;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
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

final class DownloadVideosFromYouTubeChannel extends Command
{
    public const BASE_URL_YOUTUBE_VIDEO = 'https://www.youtube.com/watch?v=';

    private DocumentManager $documentManager;
    private GoogleAccountService $googleAccountService;
    private string $tempDir;
    private array $youtubeErrors = [];

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
            ->setName('pumukit:youtube:download:videos:from:channel')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel ID')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'limit')
            ->setDescription('Import all videos from Youtube channel')
            ->setHelp(
                <<<'EOT'

Download all videos "published" and "hidden" from Youtube channel on storage.

Limit is optional to test the command. If you don't set it, all videos will be downloaded.

Usage: php bin/console pumukit:youtube:download:videos:from:channel --account={ACCOUNT} --channel={CHANNEL_ID} --limit={LIMIT}

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $input->getOption('channel');
        $limit = (int) $input->getOption('limit');

        $youtubeAccount = $this->ensureYouTubeAccountExists($input);

        $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->findBy([
            'status' => ['$in' => [MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_HIDDEN]],
            'properties.youtube_import_status' => ['$exists' => false],
            'properties.youtube_import_channel' => $channel,
        ], [], $limit ?? null);

        $progressBar = new ProgressBar($output, count($multimediaObjects));
        $progressBar->start();

        $count = 0;
        foreach($multimediaObjects as $multimediaObject) {
            $progressBar->advance();
            if (null !== $input->getOption('limit') && $count >= $input->getOption('limit')) {
                break;
            }
            $count++;

            $videoId = $multimediaObject->getProperty('youtube_import_id');
            $youtubeDownloader = new YouTubeDownloader();

            $youtubeURL = self::BASE_URL_YOUTUBE_VIDEO.$videoId;
            $downloadOptions = $youtubeDownloader->getDownloadLinks($youtubeURL);

            if (empty($downloadOptions->getAllFormats())) {
                $multimediaObject->setProperty('youtube_download_info', json_encode($downloadOptions));
                $this->documentManager->flush();

                $this->youtubeErrors[] = 'URL: '.$youtubeURL.' no formats found. Formats: '.json_encode($downloadOptions->getAllFormats());

                continue;
            }

            $url = $this->selectBestStreamFormat($downloadOptions);

            try {
                $this->moveFileToStorage($multimediaObject, $url, $downloadOptions, $channel);
                $multimediaObject->setProperty('youtube_import_status', 'downloaded');
                $this->documentManager->flush();
            } catch (\Exception $exception) {
                $this->youtubeErrors[] = 'Error moving file to storage: '.$exception->getMessage();
                continue;
            }
        }

        $progressBar->finish();
        $output->writeln(' ');

        foreach ($this->youtubeErrors as $error) {
            $output->writeln($error);
        }

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

    private function moveFileToStorage(MultimediaObject $multimediaObject, $url, DownloadOptions $downloadOptions, string $channelId): void
    {
        $videoId = $multimediaObject->getProperty('youtube_import_id');
        $mimeType = explode('video/', $this->selectBestStreamFormat($downloadOptions)->getCleanMimeType())[1];
        $this->createChannelDir($channelId);
        $file = $this->tempDir.'/'.$channelId.'/'.$videoId.'.'.$mimeType;

        $content = file_get_contents($url->url);

        file_put_contents($file, $content);
    }

    private function createChannelDir(string $channelId): void
    {
        if (!is_dir($this->tempDir.'/'.$channelId)) {
            mkdir($this->tempDir.'/'.$channelId, 0775, true);
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

}
