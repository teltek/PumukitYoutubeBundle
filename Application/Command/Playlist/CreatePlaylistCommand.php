<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\CreatePlaylistMessage;
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
    name: 'youtube:playlist:create',
    description: 'Create a new YouTube playlist'
)]
class CreatePlaylistCommand extends Command
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
            ->addArgument('account-name', InputArgument::REQUIRED, 'YouTube account name')
            ->addArgument('title', InputArgument::REQUIRED, 'Playlist title')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Playlist description', '')
            ->addOption('privacy', null, InputOption::VALUE_REQUIRED, 'Privacy status (public|private|unlisted)', 'public')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command creates a new YouTube playlist:

  <info>php %command.full_name% my-account "My Playlist Title"</info>

With description:
  <info>php %command.full_name% my-account "My Playlist" --description="Playlist description"</info>

Set privacy:
  <info>php %command.full_name% my-account "My Playlist" --privacy=private</info>

Privacy options: public, private, unlisted
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountName = $input->getArgument('account-name');
        $title = $input->getArgument('title');
        $description = $input->getOption('description');
        $privacyStatus = $input->getOption('privacy');

        // Validate privacy status
        $validPrivacyStatuses = ['public', 'private', 'unlisted'];
        if (!in_array($privacyStatus, $validPrivacyStatuses, true)) {
            $io->error("Invalid privacy status: {$privacyStatus}. Valid values: " . implode(', ', $validPrivacyStatuses));
            return Command::FAILURE;
        }

        $io->title('YouTube Playlist Creation');

        // Load account
        $account = $this->documentManager->getRepository(YoutubeAccount::class)
            ->findOneBy(['accountName' => $accountName]);

        if (!$account) {
            $io->error("YouTube account not found: {$accountName}");
            return Command::FAILURE;
        }

        if (!$account->isActive()) {
            $io->error("YouTube account is paused: {$accountName}");
            return Command::FAILURE;
        }

        $io->section('Account Details');
        $io->table(
            ['Property', 'Value'],
            [
                ['Account Name', $account->getAccountName()],
                ['Channel ID', $account->getChannelId()],
                ['Status', $account->isActive() ? '✅ Active' : '❌ Paused'],
            ]
        );

        $io->section('Playlist Details');
        $io->table(
            ['Property', 'Value'],
            [
                ['Title', $title],
                ['Description', $description ?: '(empty)'],
                ['Privacy', $privacyStatus],
            ]
        );

        try {
            // Dispatch async message
            $message = new CreatePlaylistMessage(
                accountId: $account->getId(),
                title: $title,
                description: $description,
                privacy: $privacyStatus
            );

            $this->messageBus->dispatch($message);

            $io->success('Playlist creation queued successfully!');
            $io->note([
                'The playlist creation has been added to the queue.',
                'It will be processed asynchronously.',
                'Check the logs for the creation result and playlist ID.',
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to queue playlist creation: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
