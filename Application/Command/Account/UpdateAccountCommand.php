<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Account;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:account:update',
    description: 'Update an existing YouTube account configuration'
)]
class UpdateAccountCommand extends Command
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
            ->addArgument('name', InputArgument::REQUIRED, 'Account name to update')
            ->addOption('credentials-file', null, InputOption::VALUE_REQUIRED, 'New path to OAuth credentials JSON file')
            ->addOption('channel-id', 'c', InputOption::VALUE_REQUIRED, 'New YouTube Channel ID')
            ->addOption('new-name', null, InputOption::VALUE_REQUIRED, 'New account name')
            ->addOption('pause', null, InputOption::VALUE_NONE, 'Pause the account')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Resume the account')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command updates an existing YouTube account:

  <info>php %command.full_name% my-account --channel-id=UCxxxxxxxx</info>

Update credentials:
  <info>php %command.full_name% my-account --credentials-file=config/youtube_accounts/prod/new_credentials.json</info>

Rename account:
  <info>php %command.full_name% my-account --new-name=production-account</info>

Pause account:
  <info>php %command.full_name% my-account --pause</info>

Resume account:
  <info>php %command.full_name% my-account --resume</info>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $name = $input->getArgument('name');
        $credentialsFile = $input->getOption('credentials-file');
        $channelId = $input->getOption('channel-id');
        $newName = $input->getOption('new-name');
        $pause = $input->getOption('pause');
        $resume = $input->getOption('resume');

        // Find account
        $account = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->findOneBy(['accountName' => $name]);

        if (!$account) {
            $io->error("Account with name '{$name}' not found!");
            return Command::FAILURE;
        }

        $updated = false;

        // Update credentials file
        if ($credentialsFile) {
            $fullPath = $this->projectDir.'/'.$credentialsFile;
            if (!file_exists($fullPath)) {
                $io->error("Credentials file not found: {$fullPath}");
                return Command::FAILURE;
            }

            $credentials = json_decode(file_get_contents($fullPath), true);
            if (!$credentials) {
                $io->error("Invalid JSON in credentials file");
                return Command::FAILURE;
            }

            if (!isset($credentials['web']['client_id']) && !isset($credentials['installed']['client_id'])) {
                $io->error("Invalid OAuth credentials format.");
                return Command::FAILURE;
            }

            $account->updateCredentialsPath($credentialsFile);
            $updated = true;
            $io->info("✓ Credentials path updated");
        }

        // Update channel ID
        if ($channelId) {
            $account->updateChannelId($channelId);
            $updated = true;
            $io->info("✓ Channel ID updated to: {$channelId}");
        }

        // Update account name
        if ($newName) {
            // Check if new name already exists
            $existingAccount = $this->documentManager
                ->getRepository(YoutubeAccount::class)
                ->findOneBy(['accountName' => $newName]);

            if ($existingAccount && $existingAccount->getId() !== $account->getId()) {
                $io->error("Account with name '{$newName}' already exists!");
                return Command::FAILURE;
            }

            $account->updateAccountName($newName);
            $updated = true;
            $io->info("✓ Account name changed from '{$name}' to '{$newName}'");
        }

        // Pause/Resume
        if ($pause && $resume) {
            $io->error("Cannot use both --pause and --resume options together!");
            return Command::FAILURE;
        }

        if ($pause) {
            $account->pause();
            $updated = true;
            $io->info("✓ Account paused");
        }

        if ($resume) {
            $account->resume();
            $updated = true;
            $io->info("✓ Account resumed");
        }

        if (!$updated) {
            $io->warning('No changes specified. Use --help to see available options.');
            return Command::SUCCESS;
        }

        try {
            $this->documentManager->flush();
            $io->success("Account updated successfully!");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to update account: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
