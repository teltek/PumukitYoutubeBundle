<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AccountConnectionCommand extends Command
{
    private $documentManager;
    private $googleAccountService;

    public function __construct(DocumentManager $documentManager, GoogleAccountService $googleAccountService)
    {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:account:test')
            ->setDescription('Test connection accounts')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Name of account')
            ->setHelp(
                <<<'EOT'
Check:

    php bin/console pumukit:youtube:account:test --account={login}

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>----- Testing connection with YouTube -----</info>');

        $account = $this->accountExists($input->getOption('account'));
        if (!$account) {
            $output->writeln('<notice>Account not in DB</notice>');

            return 0;
        }

        $service = $this->googleAccountService->googleServiceFromAccount($account);

        $response = $service->playlists->listPlaylists('snippet,contentDetails', [
            'maxResults' => 5,
            'mine' => true,
        ]);

        print_r($response);

        return 0;
    }

    private function accountExists(string $login): ?Tag
    {
        $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $login]);

        if ($account->getProperty('access_token') || $account->getProperty('refresh_token')) {
            return $account;
        }

        return null;
    }
}
