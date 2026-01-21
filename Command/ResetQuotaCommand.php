<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeQuotaUsage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pumukit:youtube:quota:reset',
    description: 'Reset YouTube quota for an account (for testing purposes)'
)]
final class ResetQuotaCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account-id', InputArgument::OPTIONAL, 'YouTube account ID (e.g., test-account). If not provided, resets all accounts.')
            ->setHelp('This command resets the YouTube quota for testing purposes. Use with caution in production!');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountId = $input->getArgument('account-id');

        try {
            $qb = $this->documentManager
                ->getRepository(YoutubeQuotaUsage::class)
                ->createQueryBuilder();

            if ($accountId) {
                $qb->field('youtubeAccountId')->equals($accountId);
                $io->info("Resetting quota for account: {$accountId}");
            } else {
                $io->warning('Resetting quota for ALL accounts');
            }

            // Get today's date range
            $today = new \DateTime('today');
            $tomorrow = new \DateTime('tomorrow');
            
            $qb->field('date')->gte($today)
               ->field('date')->lt($tomorrow);

            $quotas = $qb->getQuery()->execute();
            $count = 0;

            foreach ($quotas as $quota) {
                $this->documentManager->remove($quota);
                $count++;
            }

            $this->documentManager->flush();

            if ($count > 0) {
                $io->success("Successfully reset quota for {$count} document(s)");
            } else {
                $io->info('No quota documents found to reset');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Failed to reset quota: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
