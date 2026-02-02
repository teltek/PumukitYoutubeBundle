<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:playlist:remove-video',
    description: 'Remove a video from a YouTube playlist'
)]
class RemoveVideoFromPlaylistCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly YoutubeEventService $youtubeEventService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::REQUIRED, 'YouTube account name')
            ->addArgument('playlist-id', InputArgument::REQUIRED, 'YouTube playlist ID')
            ->addArgument('video-id', InputArgument::REQUIRED, 'YouTube video ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $accountName = $input->getArgument('account');
        $playlistId = $input->getArgument('playlist-id');
        $videoId = $input->getArgument('video-id');

        try {
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->findOneBy(['accountName' => $accountName]);

            if (!$account) {
                $io->error("Account '{$accountName}' not found");
                return Command::FAILURE;
            }

            $io->info("Removing video {$videoId} from playlist {$playlistId}...");

            $this->youtubeEventService->removeVideoFromPlaylist(
                $account,
                $videoId,
                $playlistId
            );

            $io->success('Video removed from playlist successfully!');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error(['Error:', $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}
