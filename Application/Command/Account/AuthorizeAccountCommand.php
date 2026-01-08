<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Account;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'youtube:account:authorize',
    description: 'Generate OAuth authorization URL for YouTube account'
)]
class AuthorizeAccountCommand extends Command
{
    public function __construct(
        private readonly GoogleClientFactory $clientFactory,
        private readonly DocumentManager $documentManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account-name', InputArgument::REQUIRED, 'YouTube account name')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command generates an OAuth authorization URL:

  <info>php %command.full_name% my-account</info>

This will generate a URL that you need to visit in your browser to authorize the application.
After authorization, you'll receive a code that you need to save using youtube:account:token.
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
            $io->error("YouTube account not found: {$accountName}");
            $io->note('Available accounts:');
            
            $accounts = $this->documentManager->getRepository(YoutubeAccount::class)->findAll();
            foreach ($accounts as $acc) {
                $io->writeln("  - {$acc->getAccountName()}");
            }
            
            return Command::FAILURE;
        }

        try {
            $authUrl = $this->clientFactory->getAuthorizationUrl($account);
            
            $io->success('Authorization URL generated successfully!');
            $io->section('Step 1: Authorize Application');
            $io->writeln('Visit this URL in your browser:');
            $io->newLine();
            $io->writeln("  <href={$authUrl}>{$authUrl}</>");
            $io->newLine();
            
            $io->section('Step 2: Get Authorization Code');
            $io->writeln('1. Click "Allow" to authorize the application');
            $io->writeln('2. You will be redirected to a URL like: http://localhost/?code=XXXXX');
            $io->writeln('3. Copy the code from the URL (everything after "code=")');
            
            $io->section('Step 3: Save Token');
            $io->writeln("Run this command with the code:");
            $io->newLine();
            $io->writeln("  php bin/console youtube:account:token {$accountName} <CODE>");
            $io->newLine();
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error generating authorization URL: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
