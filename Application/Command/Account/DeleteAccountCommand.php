<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Account;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Model\Publication;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:account:delete',
    description: 'Delete a YouTube account configuration'
)]
class DeleteAccountCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Account name to delete')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force deletion even if there are active publications')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command deletes a YouTube account:

  <info>php %command.full_name% my-account</info>

Force deletion (even with active publications):
  <info>php %command.full_name% my-account --force</info>

<comment>WARNING: Deleting an account will NOT delete videos from YouTube.
It only removes the account configuration from the system.</comment>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $name = $input->getArgument('name');
        $force = $input->getOption('force');

        // Find account
        $account = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->findOneBy(['accountName' => $name]);

        if (!$account) {
            $io->error("Account with name '{$name}' not found!");
            return Command::FAILURE;
        }

        // Check for active publications
        $publicationsCount = $this->documentManager
            ->getRepository(Publication::class)
            ->createQueryBuilder()
            ->field('youtubeAccountId')->equals($account->getId())
            ->count()
            ->getQuery()
            ->execute();

        if ($publicationsCount > 0 && !$force) {
            $io->error([
                "Cannot delete account '{$name}'.",
                "It has {$publicationsCount} associated publication(s).",
                "Use --force to delete anyway (this will NOT delete videos from YouTube).",
            ]);
            return Command::FAILURE;
        }

        // Show warning and ask for confirmation
        $io->warning([
            "You are about to delete the YouTube account: {$name}",
            "ID: {$account->getId()}",
            "Channel ID: {$account->getChannelId()}",
        ]);

        if ($publicationsCount > 0) {
            $io->caution("This account has {$publicationsCount} associated publication(s).");
        }

        $io->note('This will NOT delete videos from YouTube, only the account configuration.');

        if (!$io->confirm('Are you sure you want to continue?', false)) {
            $io->info('Deletion cancelled.');
            return Command::SUCCESS;
        }

        try {
            $this->documentManager->remove($account);
            $this->documentManager->flush();

            $io->success("YouTube account '{$name}' deleted successfully!");

            if ($publicationsCount > 0) {
                $io->warning("The {$publicationsCount} associated publication(s) still exist in the database but are now orphaned.");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to delete account: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
