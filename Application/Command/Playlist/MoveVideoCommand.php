<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Playlist;

use App\Message\MoveVideoToPlaylistMessage;
use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'youtube:playlist:move',
    description: 'Move a video from one playlist to another'
)]
class MoveVideoCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly MessageBusInterface $messageBus
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::REQUIRED, 'YouTube account name')
            ->addArgument('video-id', InputArgument::REQUIRED, 'YouTube video ID')
            ->addOption('from-playlist', null, InputOption::VALUE_REQUIRED, 'Source playlist ID')
            ->addOption('to-playlist', null, InputOption::VALUE_REQUIRED, 'Destination playlist ID')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Execute asynchronously via messenger')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command moves a video from one playlist to another.

Usage:
  <info>php %command.full_name% test-account dQw4w9WgXcQ --from-playlist=PLxxx --to-playlist=PLyyy</info>

This will:
  1. Remove the video from the source playlist
  2. Add the video to the destination playlist

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $accountName = $input->getArgument('account');
        $videoId = $input->getArgument('video-id');
        $fromPlaylist = $input->getOption('from-playlist');
        $toPlaylist = $input->getOption('to-playlist');
        $async = $input->getOption('async');

        if (!$fromPlaylist || !$toPlaylist) {
            $io->error('Both --from-playlist and --to-playlist options are required');
            return Command::FAILURE;
        }

        try {
            // Obtener cuenta
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->findOneBy(['accountName' => $accountName]);

            if (!$account) {
                $io->error("YouTube account '{$accountName}' not found");
                return Command::FAILURE;
            }

            $io->section('Move Operation');
            $io->table(
                ['Property', 'Value'],
                [
                    ['Video ID', $videoId],
                    ['From Playlist', $fromPlaylist],
                    ['To Playlist', $toPlaylist],
                    ['Account', $account->getAccountName()],
                ]
            );

            if (!$io->confirm('Do you want to move this video?', true)) {
                $io->info('Operation cancelled');
                return Command::SUCCESS;
            }

            $message = new MoveVideoToPlaylistMessage(
                $account->getId(),
                $videoId,
                $fromPlaylist,
                $toPlaylist
            );

            if ($async) {
                $this->messageBus->dispatch($message);
                $io->success('Video move queued successfully!');
            } else {
                $io->info('Moving video...');
                $this->messageBus->dispatch($message);
                $io->success('Video moved successfully!');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error(['Error moving video:', $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}
