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

class AccountCreateCommand extends Command
{
    private $client;
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
            ->setName('pumukit:youtube:account:create')
            ->setDescription('Create accounts for Youtube. Access token will be saved on youtube specific login tag')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Name of account')
            ->setHelp(
                <<<'EOT'
Check:

    php bin/console pumukit:youtube:account:create --account={login}

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>----- YouTube account creating -----</info>');

        $account = $this->accountExists($input->getOption('account'));
        if (!$account) {
            $output->writeln('<notice>Account not in DB</notice>');

            return 0;
        }

        $this->client = $this->googleAccountService->createClient($account->getProperty('login'));

        $authorizationCode = $this->requestAuthorization();
        $accessToken = $this->accessToken($authorizationCode);

        $account->setProperty('access_token', $accessToken);
        if (null === $account->getProperty('refresh_token')) {
            $account->setProperty('refresh_token', $accessToken['refresh_token']);
        }
        $this->documentManager->flush();

        $output->writeln('Access token for '.$account->getProperty('login').' created.');

        return 0;
    }

    private function accountExists(string $account)
    {
        return $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $account]);
    }

    private function requestAuthorization(): string
    {
        $authUrl = $this->client->createAuthUrl();

        printf("Open this link in your browser:\n%s\n", $authUrl);
        echo 'Enter verification code: ';

        return trim(fgets(STDIN));
    }

    private function accessToken(string $authorizationCode)
    {
        $accessToken = $this->client->fetchAccessTokenWithAuthCode($authorizationCode);
        $this->client->setAccessToken($accessToken);

        return $accessToken;
    }
}
