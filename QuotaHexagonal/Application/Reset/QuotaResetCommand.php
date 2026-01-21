<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\QuotaHexagonal\Application\Reset;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:quota:reset',
    description: 'Manually reset daily quota tracking (for testing/emergency)'
)]
class QuotaResetCommand extends Command
{
    public function __construct(
        private readonly QuotaService $quotaService,
        private readonly DocumentManager $documentManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account-name', InputArgument::REQUIRED, 'YouTube account name')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command manually resets the daily quota tracking:

  <info>php %command.full_name% my-account</info>

<comment>WARNING: This does NOT reset the actual YouTube API quota!</comment>
This only resets the local tracking. Use this for testing or if tracking data is incorrect.

YouTube's actual quota resets at midnight Pacific Time (PT) automatically.
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountName = $input->getArgument('account-name');

        // Find account
        $account = $this->documentManager->getRepository(YoutubeAccount::class)
            ->findOneBy(['accountName' => $accountName]);

        if (!$account) {
            $io->error("Account not found: {$accountName}");
            return Command::FAILURE;
        }

        // Show current status
        $status = $this->quotaService->getQuotaStatus($account->getId());

        $io->section('Current Quota Status');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Account', $account->getAccountName()],
                ['Date', $status['date']],
                ['Quota Used', number_format($status['quotaUsed']) . ' units'],
                ['Operations Count', $status['operationsCount']],
            ]
        );

        $io->warning([
            'This will RESET the local quota tracking to 0.',
            'This does NOT affect the actual YouTube API quota.',
            'YouTube quota resets automatically at midnight Pacific Time.',
        ]);

        if (!$io->confirm('Are you sure you want to reset the local quota tracking?', false)) {
            $io->info('Reset cancelled.');
            return Command::SUCCESS;
        }

        try {
            $this->quotaService->resetDailyQuota($account->getId());
            
            $io->success([
                "Quota tracking reset successfully for account: {$accountName}",
                'The tracking will start fresh from 0 units.',
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to reset quota: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
