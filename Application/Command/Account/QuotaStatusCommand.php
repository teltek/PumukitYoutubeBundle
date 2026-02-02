<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Account;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:quota:status',
    description: 'Show YouTube API quota usage status'
)]
class QuotaStatusCommand extends Command
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
            ->addArgument('account-name', InputArgument::OPTIONAL, 'YouTube account name (optional, shows all if not specified)')
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, 'Date to check (Y-m-d format, defaults to today)')
            ->addOption('operations', 'o', InputOption::VALUE_NONE, 'Show detailed operations list')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command shows YouTube API quota usage:

  <info>php %command.full_name%</info>

For specific account:
  <info>php %command.full_name% my-account</info>

For specific date:
  <info>php %command.full_name% my-account --date=2025-12-29</info>

Show operations detail:
  <info>php %command.full_name% my-account --operations</info>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountName = $input->getArgument('account-name');
        $dateString = $input->getOption('date');
        $showOperations = $input->getOption('operations');

        $date = null;
        if ($dateString) {
            try {
                $date = new \DateTimeImmutable($dateString);
            } catch (\Exception $e) {
                $io->error("Invalid date format: {$dateString}. Use Y-m-d format (e.g., 2025-12-29)");
                return Command::FAILURE;
            }
        }

        $io->title('YouTube API Quota Status');

        // Get accounts to check
        $accounts = [];
        if ($accountName) {
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->findOneBy(['accountName' => $accountName]);

            if (!$account) {
                $io->error("Account not found: {$accountName}");
                return Command::FAILURE;
            }

            $accounts = [$account];
        } else {
            $accounts = $this->documentManager->getRepository(YoutubeAccount::class)->findAll();
        }

        if (empty($accounts)) {
            $io->warning('No YouTube accounts found.');
            return Command::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->displayAccountQuota($io, $account, $date, $showOperations);
        }

        return Command::SUCCESS;
    }

    private function displayAccountQuota(
        SymfonyStyle $io,
        YoutubeAccount $account,
        ?\DateTimeInterface $date,
        bool $showOperations
    ): void {
        $status = $this->quotaService->getQuotaStatus($account->getId(), $date);

        $io->section("Account: {$account->getAccountName()}");

        // Quota summary
        $io->table(
            ['Metric', 'Value'],
            [
                ['Date', $status['date']],
                ['Quota Used', number_format($status['quotaUsed']) . ' units'],
                ['Quota Limit', number_format($status['quotaLimit']) . ' units'],
                ['Quota Remaining', number_format($status['quotaRemaining']) . ' units'],
                ['Usage Percentage', $status['percentageUsed'] . '%'],
                ['Status', $status['isExhausted'] ? '🔴 EXHAUSTED' : '🟢 Available'],
                ['Operations Count', $status['operationsCount']],
            ]
        );

        // Estimated remaining operations
        $io->section('Estimated Remaining Operations');
        $estimations = [
            ['Operation', 'Cost (units)', 'Remaining Ops'],
            ['Video Upload', '1,600', number_format($this->quotaService->getEstimatedOperations($account->getId(), 'video.upload'))],
            ['Video Update', '50', number_format($this->quotaService->getEstimatedOperations($account->getId(), 'video.update'))],
            ['Video Delete', '50', number_format($this->quotaService->getEstimatedOperations($account->getId(), 'video.delete'))],
            ['Playlist Create', '50', number_format($this->quotaService->getEstimatedOperations($account->getId(), 'playlist.create'))],
            ['Add to Playlist', '50', number_format($this->quotaService->getEstimatedOperations($account->getId(), 'playlistItem.insert'))],
            ['Upload Captions', '400', number_format($this->quotaService->getEstimatedOperations($account->getId(), 'caption.insert'))],
        ];
        $io->table([], $estimations);

        // Show detailed operations if requested
        if ($showOperations && $status['operationsCount'] > 0) {
            $quota = $this->quotaService->getOrCreateDailyQuota($account->getId(), $date);
            $operations = $quota->getOperations();

            $io->section('Operations Detail (last 20)');
            $rows = [];
            $recentOps = array_slice($operations, -20);

            foreach ($recentOps as $op) {
                $timestamp = $op['timestamp'];
                if (is_array($timestamp)) {
                    // MongoDB sometimes returns dates as arrays
                    $timestampStr = $timestamp['date'] ?? 'N/A';
                } elseif ($timestamp instanceof \DateTimeInterface) {
                    $timestampStr = $timestamp->format('H:i:s');
                } else {
                    $timestampStr = 'N/A';
                }
                
                $rows[] = [
                    $timestampStr,
                    $op['type'],
                    $op['cost'],
                    json_encode($op['metadata'] ?? [], JSON_UNESCAPED_SLASHES),
                ];
            }

            $io->table(['Time', 'Operation', 'Cost', 'Metadata'], $rows);
        }

        // Warning if quota is high
        if ($status['percentageUsed'] >= 80) {
            $io->warning("⚠️  High quota usage! Consider limiting operations or spreading them across multiple accounts.");
        }

        if ($status['isExhausted']) {
            $io->error("❌ Quota exhausted! No more operations allowed until quota resets at midnight Pacific Time.");
        }

        $io->newLine();
    }
}
