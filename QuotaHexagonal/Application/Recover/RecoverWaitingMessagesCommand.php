<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\QuotaHexagonal\Application\Recover;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Container\ContainerInterface;

#[AsCommand(
    name: 'youtube:quota:recover-waiting-messages',
    description: 'Recover messages from waiting queue after quota reset'
)]
class RecoverWaitingMessagesCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly QuotaService $quotaService,
        private readonly MessageBusInterface $messageBus,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('account-id', null, InputOption::VALUE_OPTIONAL, 'Recover messages only for specific account')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without actually doing it')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of messages to recover', 100)
            ->setHelp(<<<'HELP'
This command recovers messages from the quota.waiting queue and moves them back
to the events queue after the daily quota has been reset.

It should be run daily after midnight (Pacific Time) when YouTube's quota resets.

Examples:
  # Recover all waiting messages
  php bin/console youtube:quota:recover-waiting-messages

  # Dry run to see what would happen
  php bin/console youtube:quota:recover-waiting-messages --dry-run

  # Recover only for specific account
  php bin/console youtube:quota:recover-waiting-messages --account-id=ABC123

  # Limit to 50 messages
  php bin/console youtube:quota:recover-waiting-messages --limit=50

Add to crontab (runs at 00:30 AM Pacific Time = 09:30 AM CET):
  30 9 * * * cd /path/to/project && php bin/console youtube:quota:recover-waiting-messages
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountId = $input->getOption('account-id');
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $io->title('YouTube Quota - Recover Waiting Messages');

        if ($dryRun) {
            $io->warning('DRY RUN MODE - No messages will be actually moved');
        }

        try {
            // Get the waiting transport
            $waitingTransport = $this->container->get('messenger.transport.pumukit.youtube.quota.waiting');
            
            if (!$waitingTransport instanceof TransportInterface) {
                $io->error('Waiting transport not found or invalid');
                return Command::FAILURE;
            }

            // Count messages in waiting queue
            $messageCount = 0;
            if ($waitingTransport instanceof MessageCountAwareInterface) {
                $messageCount = $waitingTransport->getMessageCount();
            }

            $io->info(sprintf('Messages in waiting queue: %d', $messageCount));

            if ($messageCount === 0) {
                $io->success('No messages waiting in queue');
                return Command::SUCCESS;
            }

            // Get all accounts (or specific one)
            $accounts = $this->getAccounts($accountId);
            
            if (empty($accounts)) {
                $io->error('No accounts found');
                return Command::FAILURE;
            }

            $io->section('Checking quota status for accounts');
            $accountsReady = [];

            foreach ($accounts as $account) {
                $quotaStatus = $this->quotaService->getQuotaStatus($account->getId());
                
                $io->writeln(sprintf(
                    '  • %s (%s): %d / %d used (%.1f%%)',
                    $account->getAccountName(),
                    $account->getId(),
                    $quotaStatus['used'],
                    $quotaStatus['limit'],
                    $quotaStatus['percentage']
                ));

                // Check if quota has been reset (used < 500 indicates likely reset)
                if ($quotaStatus['used'] < 500) {
                    $accountsReady[] = $account->getId();
                    $io->writeln('    ✓ Quota appears to be reset - ready to recover messages');
                } else {
                    $io->writeln('    ✗ Quota still high - skipping this account');
                }
            }

            if (empty($accountsReady)) {
                $io->warning('No accounts with reset quota found');
                return Command::SUCCESS;
            }

            // Recover messages
            $io->section('Recovering messages from waiting queue');
            
            $recovered = 0;
            $skipped = 0;
            $errors = 0;

            // Receive messages from waiting queue
            $envelopes = $waitingTransport->get();
            $processed = 0;

            while ($envelopes->valid() && $processed < $limit) {
                $envelope = $envelopes->current();
                $message = $envelope->getMessage();

                if ($message instanceof \Pumukit\YoutubeBundle\QuotaHexagonal\Application\Wait\WaitingMessage) {
                    $msgAccountId = $message->getAccountId();
                    
                    // Check if this account is ready
                    if (in_array($msgAccountId, $accountsReady)) {
                        $io->writeln(sprintf(
                            '  Recovering: %s (account: %s, cost: %d)',
                            $message->getOriginalMessageClass(),
                            $msgAccountId,
                            $message->getQuotaCost()
                        ));

                        if (!$dryRun) {
                            try {
                                // Dispatch the original message to events queue
                                $this->messageBus->dispatch($message->getOriginalMessage());
                                
                                // Acknowledge (remove) from waiting queue
                                $waitingTransport->ack($envelope);
                                
                                $recovered++;
                            } catch (\Exception $e) {
                                $io->error(sprintf('Error recovering message: %s', $e->getMessage()));
                                $this->logger->error('[RecoverWaitingMessages] Error', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString(),
                                ]);
                                $errors++;
                            }
                        } else {
                            $recovered++; // Count for dry run
                        }
                    } else {
                        $io->writeln(sprintf(
                            '  Skipping: %s (account %s not ready)',
                            $message->getOriginalMessageClass(),
                            $msgAccountId
                        ), OutputInterface::VERBOSITY_VERBOSE);
                        $skipped++;
                    }
                }

                $envelopes->next();
                $processed++;
            }

            // Summary
            $io->section('Summary');
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Messages recovered', $recovered],
                    ['Messages skipped', $skipped],
                    ['Errors', $errors],
                    ['Accounts ready', count($accountsReady)],
                ]
            );

            if ($dryRun) {
                $io->note('This was a dry run - no messages were actually moved');
            }

            if ($recovered > 0) {
                $io->success(sprintf('Successfully recovered %d messages from waiting queue', $recovered));
            } else {
                $io->info('No messages were recovered');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Error executing command: ' . $e->getMessage());
            $this->logger->error('[RecoverWaitingMessages] Command error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * @return YoutubeAccount[]
     */
    private function getAccounts(?string $accountId): array
    {
        $repo = $this->documentManager->getRepository(YoutubeAccount::class);

        if ($accountId) {
            $account = $repo->find($accountId);
            return $account ? [$account] : [];
        }

        return $repo->findAll();
    }
}
