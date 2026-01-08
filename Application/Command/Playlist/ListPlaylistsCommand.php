<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube as YouTubeService;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:playlist:list',
    description: 'List all playlists from a YouTube account'
)]
class ListPlaylistsCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $clientFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::REQUIRED, 'YouTube account name')
            ->addOption('max-results', null, InputOption::VALUE_REQUIRED, 'Maximum results', 25);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $accountName = $input->getArgument('account');
        $maxResults = (int) $input->getOption('max-results');

        try {
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->findOneBy(['accountName' => $accountName]);

            if (!$account) {
                $io->error("Account '{$accountName}' not found");
                return Command::FAILURE;
            }

            $client = $this->clientFactory->createClient($account);
            $youtube = new YouTubeService($client);

            $io->title("Playlists for account: {$account->getAccountName()}");

            $playlists = [];
            $pageToken = '';

            do {
                $response = $youtube->playlists->listPlaylists('snippet,contentDetails,status', [
                    'mine' => true,
                    'maxResults' => $maxResults,
                    'pageToken' => $pageToken,
                ]);

                foreach ($response->getItems() as $playlist) {
                    $playlists[] = [
                        $playlist->getId(),
                        $playlist->getSnippet()->getTitle(),
                        $playlist->getContentDetails()->getItemCount(),
                        $playlist->getStatus()->getPrivacyStatus(),
                        $playlist->getSnippet()->getPublishedAt(),
                    ];
                }

                $pageToken = $response->getNextPageToken();
            } while ($pageToken && count($playlists) < $maxResults);

            if (empty($playlists)) {
                $io->warning('No playlists found');
                return Command::SUCCESS;
            }

            $io->table(
                ['Playlist ID', 'Title', 'Videos', 'Privacy', 'Created'],
                $playlists
            );

            $io->success(sprintf('Found %d playlist(s)', count($playlists)));
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error(['Error:', $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}
