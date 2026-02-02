<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Quota;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Comando para procesar videos en estado "quota waiting"
 * 
 * Este comando:
 * 1. Consume mensajes de la cola "pumukit.youtube.quota.waiting"
 * 2. Re-despacha el mensaje original a "pumukit.youtube.events"
 * 3. Se ejecuta periódicamente vía cron para mover videos cuando hay cuota disponible
 * 
 * Uso:
 *   bin/console youtube:quota:process-waiting
 *   bin/console youtube:quota:process-waiting --limit=50
 *   bin/console youtube:quota:process-waiting --account=test-account
 */
#[AsCommand(
    name: 'youtube:quota:process-waiting',
    description: 'Process videos in quota waiting queue and dispatch them to events queue'
)]
class ProcessQuotaWaitingCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TransportInterface $quotaWaitingTransport,
        private readonly LoggerInterface $logger,
        private readonly DocumentManager $documentManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of messages to process',
                10
            )
            ->addOption(
                'account',
                'a',
                InputOption::VALUE_OPTIONAL,
                'Process only messages for specific YouTube account'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Show what would be processed without actually processing'
            )
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command processes videos waiting in quota queue.

When YouTube quota is exceeded, videos are stored in a waiting queue.
This command checks the queue and re-dispatches messages to the events queue
when quota becomes available (typically after daily reset at midnight Pacific Time).

<info>Examples:</info>

  Process up to 10 waiting messages:
    <comment>%command.full_name%</comment>

  Process up to 50 messages:
    <comment>%command.full_name% --limit=50</comment>

  Process only for specific account:
    <comment>%command.full_name% --account=test-account</comment>

  Dry run (preview without processing):
    <comment>%command.full_name% --dry-run</comment>

<info>Cron Setup:</info>

  Run every hour:
    <comment>0 * * * * /usr/bin/docker exec pumukit-php-1 bin/console youtube:quota:process-waiting --limit=100</comment>

  Run every 30 minutes:
    <comment>*/30 * * * * /usr/bin/docker exec pumukit-php-1 bin/console youtube:quota:process-waiting</comment>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $limit = (int) $input->getOption('limit');
        $accountFilter = $input->getOption('account');
        $isDryRun = $input->getOption('dry-run');

        $io->title('YouTube Quota Waiting Queue Processor');

        if ($isDryRun) {
            $io->warning('DRY RUN MODE - No messages will be actually processed');
        }

        // Limpiar registros de cuota antiguos (más de 7 días)
        if (!$isDryRun) {
            $this->cleanOldQuotaRecords($io);
        }

        // Verificar cuántos mensajes hay en la cola
        $messageCount = 0;
        if ($this->quotaWaitingTransport instanceof MessageCountAwareInterface) {
            $messageCount = $this->quotaWaitingTransport->getMessageCount();
            $io->info(sprintf('Messages in quota waiting queue: %d', $messageCount));
        }

        if ($messageCount === 0) {
            $io->success('No messages in quota waiting queue');
            return Command::SUCCESS;
        }

        $io->section('Processing waiting messages');
        
        $processed = 0;
        $skipped = 0;
        $errors = 0;

        $progressBar = $io->createProgressBar(min($limit, $messageCount));
        $progressBar->start();

        // Procesar mensajes de la cola
        while ($processed < $limit) {
            $envelopes = $this->quotaWaitingTransport->get();
            
            if (empty($envelopes)) {
                break; // No hay más mensajes
            }

            foreach ($envelopes as $envelope) {
                if ($processed >= $limit) {
                    break;
                }

                try {
                    $message = $envelope->getMessage();

                    // Verificar si es un WaitingMessage
                    if (!$message instanceof \Pumukit\YoutubeBundle\Application\Message\WaitingMessage) {
                        $io->warning(sprintf('Unexpected message type: %s', get_class($message)));
                        $this->quotaWaitingTransport->ack($envelope);
                        $skipped++;
                        continue;
                    }

                    // Filtrar por cuenta si se especificó
                    if ($accountFilter && $message->getAccountId() !== $accountFilter) {
                        $skipped++;
                        // No hacemos ACK - dejamos el mensaje en la cola
                        continue;
                    }

                    if ($isDryRun) {
                        $io->writeln(sprintf(
                            '  [DRY RUN] Would process: Account=%s, Operation=%s, Priority=%d',
                            $message->getAccountId(),
                            $message->getOperation(),
                            $message->getPriority()
                        ));
                        $processed++;
                        continue;
                    }

                    // Re-despachar el mensaje original a la cola de eventos
                    $originalMessage = $message->getOriginalMessage();
                    $this->messageBus->dispatch($originalMessage);

                    $this->logger->info('[ProcessQuotaWaiting] Message re-dispatched', [
                        'accountId' => $message->getAccountId(),
                        'operation' => $message->getOperation(),
                        'originalMessageClass' => get_class($originalMessage),
                    ]);

                    // Confirmar que el mensaje fue procesado
                    $this->quotaWaitingTransport->ack($envelope);
                    
                    $processed++;
                    $progressBar->advance();

                } catch (\Exception $e) {
                    $this->logger->error('[ProcessQuotaWaiting] Error processing message', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Rechazar el mensaje para que vaya a failed queue
                    $this->quotaWaitingTransport->reject($envelope);
                    
                    $errors++;
                }
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Resumen
        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Processed', $processed],
                ['Skipped', $skipped],
                ['Errors', $errors],
                ['Remaining in queue', max(0, $messageCount - $processed)],
            ]
        );

        if ($errors > 0) {
            $io->warning(sprintf('%d messages failed to process', $errors));
            return Command::FAILURE;
        }

        if ($processed > 0) {
            $io->success(sprintf('Successfully processed %d messages from quota waiting queue', $processed));
        } else {
            $io->info('No messages were processed');
        }

        return Command::SUCCESS;
    }

    /**
     * Limpia registros de cuota antiguos (más de 7 días)
     * Esto mantiene la base de datos limpia y mejora el rendimiento
     */
    private function cleanOldQuotaRecords(SymfonyStyle $io): void
    {
        try {
            $cutoffDate = new \DateTime('-7 days');
            $cutoffDate->setTime(0, 0, 0);

            $result = $this->documentManager
                ->getDocumentCollection('Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeQuotaUsage')
                ->deleteMany([
                    'date' => ['$lt' => new \MongoDB\BSON\UTCDateTime($cutoffDate)],
                ]);

            if ($result->getDeletedCount() > 0) {
                $io->writeln(sprintf(
                    '<comment>Cleaned %d old quota records (older than %s)</comment>',
                    $result->getDeletedCount(),
                    $cutoffDate->format('Y-m-d')
                ));

                $this->logger->info('[ProcessQuotaWaiting] Cleaned old quota records', [
                    'deletedCount' => $result->getDeletedCount(),
                    'cutoffDate' => $cutoffDate->format('Y-m-d'),
                ]);
            }
        } catch (\Exception $e) {
            $io->warning(sprintf('Failed to clean old quota records: %s', $e->getMessage()));
            
            $this->logger->error('[ProcessQuotaWaiting] Error cleaning old quota records', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
