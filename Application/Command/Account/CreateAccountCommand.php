<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Account;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:account:create',
    description: 'Create a new YouTube account configuration'
)]
class CreateAccountCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly string $projectDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Account name (unique identifier)')
            ->addArgument('credentials-file', InputArgument::REQUIRED, 'Path to OAuth credentials JSON file (relative to project root)')
            ->addOption('channel-id', 'c', InputOption::VALUE_REQUIRED, 'YouTube Channel ID (optional, will be fetched if not provided)')
            ->addOption('paused', 'p', InputOption::VALUE_NONE, 'Create account in paused state')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command creates a new YouTube account configuration:

  <info>php %command.full_name% my-account config/youtube_accounts/prod/credentials.json</info>

With channel ID:
  <info>php %command.full_name% my-account config/youtube_accounts/prod/credentials.json --channel-id=UCxxxxxxxx</info>

Create paused:
  <info>php %command.full_name% my-account config/youtube_accounts/prod/credentials.json --paused</info>

The credentials file should be a valid Google OAuth 2.0 client secret JSON file.
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $name = $input->getArgument('name');
        $credentialsFile = $input->getArgument('credentials-file');
        $channelId = $input->getOption('channel-id');
        $paused = $input->getOption('paused');

        // Validate account name doesn't exist
        $existingAccount = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->findOneBy(['accountName' => $name]);

        if ($existingAccount) {
            $io->error("Account with name '{$name}' already exists!");
            return Command::FAILURE;
        }

        // Validate credentials file
        $fullPath = $this->projectDir.'/'.$credentialsFile;
        if (!file_exists($fullPath)) {
            $io->error("Credentials file not found: {$fullPath}");
            return Command::FAILURE;
        }

        // Validate JSON format
        $credentials = json_decode(file_get_contents($fullPath), true);
        if (!$credentials) {
            $io->error("Invalid JSON in credentials file");
            return Command::FAILURE;
        }

        // Validate it's a valid OAuth credentials file
        if (!isset($credentials['web']['client_id']) && !isset($credentials['installed']['client_id'])) {
            $io->error("Invalid OAuth credentials format. Must contain 'web' or 'installed' configuration.");
            return Command::FAILURE;
        }

        // If no channel ID provided, try to fetch it from YouTube API
        if (!$channelId) {
            $io->note('No channel ID provided. You can set it later with youtube:account:update');
            $channelId = 'PENDING'; // Temporary placeholder
        }

        try {
            // Create account
            $account = YoutubeAccount::create(
                $name,
                $channelId,
                $credentialsFile,
                $paused
            );

            $this->documentManager->persist($account);
            $this->documentManager->flush();

            $io->success([
                "YouTube account '{$name}' created successfully!",
                "ID: {$account->getId()}",
                "Channel ID: {$channelId}",
                "Credentials: {$credentialsFile}",
                "Status: ".($paused ? 'PAUSED' : 'ACTIVE'),
            ]);

            if ($channelId === 'PENDING') {
                $io->warning([
                    'Channel ID is set to PENDING.',
                    'Please authorize the account and update the channel ID:',
                    "  php bin/console youtube:account:authorize {$name}",
                ]);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to create account: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
