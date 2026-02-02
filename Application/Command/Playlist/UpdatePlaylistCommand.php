<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\UpdatePlaylistMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'youtube:playlist:update',
    description: 'Update a YouTube playlist'
)]
class UpdatePlaylistCommand extends Command
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
            ->addArgument('playlist-id', InputArgument::REQUIRED, 'YouTube playlist ID')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'New title')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'New description')
            ->addOption('privacy', null, InputOption::VALUE_REQUIRED, 'Privacy status (public, unlisted, private)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $accountName = $input->getArgument('account');
        $playlistId = $input->getArgument('playlist-id');
        $title = $input->getOption('title');
        $description = $input->getOption('description');
        $privacy = $input->getOption('privacy');

        if (!$title && !$description && !$privacy) {
            $io->error('At least one option must be provided: --title, --description, or --privacy');
            return Command::FAILURE;
        }

        try {
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->findOneBy(['accountName' => $accountName]);

            if (!$account) {
                $io->error("Account '{$accountName}' not found");
                return Command::FAILURE;
            }

            // Prepare update data
            $updateData = [];
            if ($title) {
                $updateData['title'] = $title;
            }
            if ($description) {
                $updateData['description'] = $description;
            }
            if ($privacy) {
                $updateData['privacy'] = $privacy;
            }

            // Dispatch async message
            $message = new UpdatePlaylistMessage(
                accountId: $account->getId(),
                playlistId: $playlistId,
                updateData: $updateData
            );

            $this->messageBus->dispatch($message);

            $io->success("Playlist update queued successfully!");
            $io->note('The playlist will be updated in the background. Check the logs for the result.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error(['Error:', $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}
