<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\GoogleClientFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncPlaylistsFromYoutubeCommand extends Command
{
    protected static $defaultName = 'pumukit:youtube:playlists:sync';

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $googleClientFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Sync playlists from YouTube to MongoDB')
            ->addOption('account-id', 'a', InputOption::VALUE_OPTIONAL, 'Sync only for specific account ID')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force sync even if playlists already exist')
            ->setHelp(<<<'HELP'
This command syncs all YouTube playlists from your YouTube account to the local MongoDB database.

Examples:
  # Sync playlists from all accounts
  php bin/console pumukit:youtube:playlists:sync

  # Sync playlists from a specific account
  php bin/console pumukit:youtube:playlists:sync --account-id=ABC123

  # Force re-sync (update existing playlists)
  php bin/console pumukit:youtube:playlists:sync --force
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountId = $input->getOption('account-id');
        $force = $input->getOption('force');

        $io->title('YouTube Playlists Sync');

        // Get accounts to sync
        $accountsQuery = $this->documentManager->getRepository(YoutubeAccount::class)->createQueryBuilder();
        if ($accountId) {
            $accountsQuery->field('id')->equals($accountId);
        }
        $accounts = $accountsQuery->getQuery()->execute();

        if (count($accounts) === 0) {
            $io->error('No YouTube accounts found');
            return Command::FAILURE;
        }

        $totalSynced = 0;
        $totalUpdated = 0;
        $totalErrors = 0;

        foreach ($accounts as $account) {
            $io->section("Syncing playlists for account: {$account->getAccountName()} ({$account->getId()})");

            try {
                // Create Google client
                $client = $this->googleClientFactory->createClient($account);
                $youtube = new \Google_Service_YouTube($client);

                // Get all playlists from YouTube
                $playlists = [];
                $pageToken = null;

                do {
                    $playlistsResponse = $youtube->playlists->listPlaylists('snippet,status,contentDetails', [
                        'mine' => true,
                        'maxResults' => 50,
                        'pageToken' => $pageToken,
                    ]);

                    foreach ($playlistsResponse->getItems() as $item) {
                        $playlists[] = $item;
                    }

                    $pageToken = $playlistsResponse->getNextPageToken();
                } while ($pageToken);

                $io->writeln("Found " . count($playlists) . " playlists on YouTube");

                // Sync each playlist to MongoDB
                foreach ($playlists as $ytPlaylist) {
                    $youtubeId = $ytPlaylist->getId();
                    $title = $ytPlaylist->getSnippet()->getTitle();
                    $description = $ytPlaylist->getSnippet()->getDescription() ?? '';
                    $privacy = $ytPlaylist->getStatus()->getPrivacyStatus();
                    $videoCount = $ytPlaylist->getContentDetails()->getItemCount() ?? 0;

                    // Check if playlist already exists
                    $existingPlaylist = $this->documentManager
                        ->getRepository(YoutubePlaylist::class)
                        ->findOneBy([
                            'youtubeId' => $youtubeId,
                            'accountId' => $account->getId(),
                        ]);

                    if ($existingPlaylist) {
                        if ($force) {
                            // Update existing
                            $existingPlaylist->updateTitle($title);
                            $existingPlaylist->updateDescription($description);
                            $existingPlaylist->updatePrivacy($privacy);
                            $existingPlaylist->updateVideoCount($videoCount);
                            $totalUpdated++;
                            $io->writeln("  ✓ Updated: {$title}");
                        } else {
                            $io->writeln("  - Skipped (exists): {$title}");
                        }
                    } else {
                        // Create new
                        $newPlaylist = YoutubePlaylist::create(
                            $account->getId(),
                            $youtubeId,
                            $title,
                            $description,
                            $privacy
                        );
                        $newPlaylist->updateVideoCount($videoCount);
                        $this->documentManager->persist($newPlaylist);
                        $totalSynced++;
                        $io->writeln("  ✓ Synced: {$title}");
                    }
                }

                $this->documentManager->flush();

            } catch (\Exception $e) {
                $io->error("Error syncing playlists for account {$account->getAccountName()}: {$e->getMessage()}");
                $totalErrors++;
            }
        }

        $io->newLine();
        $io->success([
            "Sync completed!",
            "New playlists synced: {$totalSynced}",
            "Playlists updated: {$totalUpdated}",
            "Errors: {$totalErrors}",
        ]);

        return Command::SUCCESS;
    }
}
