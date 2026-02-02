<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\AssignToPlaylistsMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Model\Publication;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'youtube:playlist:assign',
    description: 'Assign uploaded video to YouTube playlists'
)]
class AssignVideoToPlaylistCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly DocumentManager $documentManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('multimedia-object-id', InputArgument::REQUIRED, 'MultimediaObject ID')
            ->addOption('playlist-ids', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'YouTube Playlist IDs')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command assigns a video to YouTube playlists:

  <info>php %command.full_name% 507f1f77bcf86cd799439011 --playlist-ids=PLxxxxxx</info>

Multiple playlists:
  <info>php %command.full_name% 507f1f77bcf86cd799439011 --playlist-ids=PLxxxxxx --playlist-ids=PLyyyyyy</info>

Without confirmation:
  <info>php %command.full_name% 507f1f77bcf86cd799439011 --playlist-ids=PLxxxxxx -n</info>

The video must already be uploaded to YouTube.
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $multimediaObjectId = $input->getArgument('multimedia-object-id');
        $playlistIds = $input->getOption('playlist-ids');

        $io->title('YouTube Playlist Assignment');

        // Check if publication exists
        $publication = $this->documentManager->getRepository(Publication::class)
            ->findOneBy(['multimediaObjectId' => $multimediaObjectId]);

        if (!$publication) {
            $io->error("Publication not found for MultimediaObject: {$multimediaObjectId}");
            return Command::FAILURE;
        }

        if (!$publication->isUploaded() && $publication->getStatus()->value !== 'in_playlist') {
            $io->error("Video must be uploaded before assigning to playlists. Current status: {$publication->getStatus()->value}");
            return Command::FAILURE;
        }

        $io->section('Publication Details');
        $io->table(
            ['Property', 'Value'],
            [
                ['Multimedia Object ID', $publication->getMultimediaObjectId()],
                ['YouTube Video ID', $publication->getYoutubeVideoId()],
                ['Status', $publication->getStatus()->value],
                ['Current Playlists', implode(', ', $publication->getPlaylists()) ?: 'None'],
            ]
        );

        // Use provided playlist IDs or existing ones
        if (empty($playlistIds)) {
            $playlistIds = $publication->getPlaylists();
        }

        if (empty($playlistIds)) {
            $io->warning('No playlists specified and publication has no configured playlists');
            $io->note('Use --playlist-ids option to specify playlists: --playlist-ids=PLxxxxx --playlist-ids=PLyyyyy');
            return Command::FAILURE;
        }

        $io->section('Playlists to Assign');
        $io->listing($playlistIds);

        if (!$input->getOption('no-interaction') && !$io->confirm('Do you want to proceed with playlist assignment?', true)) {
            $io->info('Operation cancelled');
            return Command::SUCCESS;
        }

        // Dispatch message
        $message = new AssignToPlaylistsMessage(
            $multimediaObjectId,
            [
                'playlists' => $playlistIds,
                'youtube_account_id' => $publication->getYoutubeAccountId(),
            ]
        );

        $this->messageBus->dispatch($message);

        $io->success('Playlist assignment message dispatched successfully!');
        $io->note('Check RabbitMQ queue and logs for processing status');

        return Command::SUCCESS;
    }
}
