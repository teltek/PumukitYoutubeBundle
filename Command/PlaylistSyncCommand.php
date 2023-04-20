<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Services\PlaylistInsertService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PlaylistSyncCommand extends Command
{
    private $documentManager;
    private $playlistInsertService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        PlaylistInsertService $playlistInsertService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->playlistInsertService = $playlistInsertService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:playlist:sync')
            ->setDescription('Sync playlist from/in YouTube.')
            ->setHelp(
                <<<'EOT'
Command to sync playlist depends off configuration.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $youtubeAccounts = $this->getAllYouTubeAccounts();
        foreach ($youtubeAccounts as $account) {
            try {
                $this->playlistInsertService->syncAll($account);
            } catch (\Exception $exception) {
                $errorLog = sprintf("[YouTube] Playlist sync on account %s failed. Error: %s", $account->getProperty('login'), $exception->getMessage());
                $this->logger->error($errorLog);

                continue;
            }
        }

        return 0;
    }

    private function getAllYouTubeAccounts(): array
    {
        return $this->documentManager->getRepository(Tag::class)->findBy([
            'properties.login' => ['$exists' => true],
        ]);
    }
}
