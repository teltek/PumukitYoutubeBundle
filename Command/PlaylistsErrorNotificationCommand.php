<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Services\NotificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PlaylistsErrorNotificationCommand extends Command
{
    private $documentManager;
    private $notificationService;

    public function __construct(DocumentManager $documentManager, NotificationService $notificationService)
    {
        $this->documentManager = $documentManager;
        $this->notificationService = $notificationService;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:playlist:error:notification')
            ->setDescription('Notification of all playlists errors saved on PuMuKIT.')
            ->setHelp(
                <<<'EOT'
Notification of all playlists errors saved on PuMuKIT.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $elements = $this->getAllPlaylistErrors();
        $result['playlistUploadError'] = $elements;
        $this->notificationService->notificationVideoErrorResult($result);

        return 0;
    }

    private function getAllPlaylistErrors()
    {
        return $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.youtube_playlist' => true,
            'properties.youtube_error' => ['$exists' => true],
        ]);
    }
}
