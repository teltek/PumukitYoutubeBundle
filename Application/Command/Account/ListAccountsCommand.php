<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Account;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:account:list',
    description: 'List all YouTube accounts'
)]
class ListAccountsCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('active', 'a', InputOption::VALUE_NONE, 'Show only active accounts')
            ->addOption('paused', 'p', InputOption::VALUE_NONE, 'Show only paused accounts')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command lists all YouTube accounts:

  <info>php %command.full_name%</info>

Show only active accounts:
  <info>php %command.full_name% --active</info>

Show only paused accounts:
  <info>php %command.full_name% --paused</info>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $showActive = $input->getOption('active');
        $showPaused = $input->getOption('paused');

        // Build query criteria
        $criteria = [];
        if ($showActive) {
            $criteria['paused'] = false;
        } elseif ($showPaused) {
            $criteria['paused'] = true;
        }

        $accounts = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->findBy($criteria, ['createdAt' => 'ASC']);

        if (empty($accounts)) {
            $io->warning('No YouTube accounts found.');
            return Command::SUCCESS;
        }

        $io->title('YouTube Accounts');

        $rows = [];
        foreach ($accounts as $account) {
            $rows[] = [
                $account->getId(),
                $account->getAccountName(),
                $account->getChannelId(),
                $account->isPaused() ? '❌ PAUSED' : '✅ ACTIVE',
                $account->getCredentialsPath(),
                $account->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        $io->table(
            ['ID', 'Name', 'Channel ID', 'Status', 'Credentials Path', 'Created At'],
            $rows
        );

        $io->info('Total accounts: '.count($accounts));

        return Command::SUCCESS;
    }
}
