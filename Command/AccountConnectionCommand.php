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
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'UID of channel')
            ->setHelp(
                <<<'EOT'
Check:

    php bin/console pumukit:youtube:account:test --account={login} --channel={channelId}

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>----- Testing connection with YouTube -----</info>');

        $youtubeAccount = $this->ensureYouTubeAccountExists($input);

        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        $queryParams = [
            'channelId' => $input->getOption('channel'),
            'maxResults' => 5,
        ];

        $response = $service->playlists->listPlaylists('snippet', $queryParams);
        $output->writeln('Number of playlist of account: '.$response->pageInfo->getTotalResults());

        return 0;
    }

    private function ensureYouTubeAccountExists(InputInterface $input): Tag
    {
        $youtubeAccount = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $input->getOption('account'),
        ]);

        if (!$youtubeAccount) {
            throw new \Exception('Account not found');
        }

        return $youtubeAccount;
    }
}
