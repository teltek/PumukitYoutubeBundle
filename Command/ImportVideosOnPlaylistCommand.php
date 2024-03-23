<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\ChannelListResponse;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportVideosOnPlaylistCommand extends Command
{
    private DocumentManager $documentManager;
    private GoogleAccountService $googleAccountService;

    private string $channelId;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
    ) {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:import:videos:on:playlist')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel ID')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'limit')
            ->setDescription('Import all videos on playlist')
            ->setHelp(
                <<<'EOT'

Import all videos from Youtube channel

Limit is optional to test the command.

Usage: php bin/console pumukit:youtube:import:videos:on:playlist --account={ACCOUNT} --channel={CHANNEL_ID} --limit={LIMIT}

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

        $count = 0;
        $queryParams = [
            'maxResults' => 50,
        ];

        $seriesPlaylist = $this->documentManager->getRepository(Series::class)->findBy([
            'properties.youtube_import_type' => ['$exists' => true],
            'properties.youtube_import_playlist_channel' => $this->channelId,
        ]);

        $progressBar = new ProgressBar($output, count($seriesPlaylist));
        $progressBar->start();

        foreach ($seriesPlaylist as $playlist) {
            $progressBar->advance();

            $queryParams['playlistId'] = $playlist->getProperty('youtube_import_id');

            $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);
            $response = $service->playlistItems->listPlaylistItems('snippet', $queryParams);
            $nextPageToken = null;

            if (0 === count($response->getItems())) {
                continue;
            }

            do {
                if (null !== $input->getOption('limit') && $count >= $input->getOption('limit')) {
                    break;
                }

                if (null !== $nextPageToken) {
                    $queryParams['pageToken'] = $nextPageToken;
                }

                $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);
                $response = $service->playlistItems->listPlaylistItems('snippet', $queryParams);
                $nextPageToken = $response->getNextPageToken();

                foreach ($response->getItems() as $item) {
                    $videoId = $item->getSnippet()->getResourceId()->getVideoId();

                    $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                        'properties.youtube_import_id' => $videoId,
                    ]);

                    if (!$multimediaObject instanceof MultimediaObject) {
                        continue;
                    }

                    $multimediaObject->setSeries($playlist);
                    if (0 == $count % 50) {
                        $this->documentManager->flush();
                        $this->documentManager->clear();
                    }
                }
            } while (null !== $nextPageToken);
        }

        $progressBar->finish();

        $this->documentManager->flush();
        $this->documentManager->clear();

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
        $channels = $this->channelInfo($channel, $service);

        return $channels->getItems()[0]->getId();
    }

    private function channelInfo(string $channel, \Google_Service_YouTube $service): ChannelListResponse
    {
        $queryParams = ['id' => $channel];

        return $service->channels->listChannels('snippet', $queryParams);
    }
}
