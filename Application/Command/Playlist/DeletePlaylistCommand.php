<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\DeletePlaylistMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'youtube:playlist:delete',
    description: 'Delete a YouTube playlist'
)]
class DeletePlaylistCommand extends Command
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
            ->addArgument('playlist-id', InputArgument::REQUIRED, 'YouTube playlist ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $accountName = $input->getArgument('account');
        $playlistId = $input->getArgument('playlist-id');

        try {
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->findOneBy(['accountName' => $accountName]);

            if (!$account) {
                $io->error("Account '{$accountName}' not found");
                return Command::FAILURE;
            }

            $io->warning("You are about to queue the deletion of playlist '{$playlistId}'");
            
            if (!$io->confirm('Are you sure you want to delete this playlist?', false)) {
                $io->info('Operation cancelled');
                return Command::SUCCESS;
            }

            // Dispatch async message
            $message = new DeletePlaylistMessage(
                accountId: $account->getId(),
                playlistId: $playlistId
            );

            $this->messageBus->dispatch($message);

            $io->success('Playlist deletion queued successfully!');
            $io->note('The playlist will be deleted in the background. Check the logs for the result.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error(['Error:', $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}
