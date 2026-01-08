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
    name: 'youtube:account:token',
    description: 'Save OAuth token for YouTube account after authorization'
)]
class SaveTokenCommand extends Command
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
            ->addArgument('code', InputArgument::REQUIRED, 'OAuth authorization code from Google')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command saves the OAuth token after authorization:

  <info>php %command.full_name% my-account 4/0AdLIrYexxxxxxxxxxx</info>

The authorization code is obtained from the redirect URL after clicking "Allow"
in the OAuth consent screen.
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountName = $input->getArgument('account-name');
        $code = $input->getArgument('code');

        // Find account
        $account = $this->documentManager->getRepository(YoutubeAccount::class)
            ->findOneBy(['accountName' => $accountName]);

        if (!$account) {
            $io->error("YouTube account not found: {$accountName}");
            return Command::FAILURE;
        }

        try {
            $this->clientFactory->handleOAuthCallback($account, $code);
            
            $io->success('Access token saved successfully!');
            $io->writeln([
                '',
                "Account '{$accountName}' is now authenticated and ready to use.",
                '',
                'You can now:',
                '  • Upload videos to YouTube',
                '  • Update video metadata',
                '  • Manage playlists',
                '',
            ]);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error saving access token: ' . $e->getMessage());
            $io->note('Make sure the authorization code is correct and has not expired.');
            return Command::FAILURE;
        }
    }
}
