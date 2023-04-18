<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\NotificationService;
use Pumukit\YoutubeBundle\Services\VideoListService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VideoUpdatePendingStatusCommand extends VideoUpdateStatusCommand
{
    protected $okUpdates = [];
    protected $failedUpdates = [];
    protected $errors = [];
    protected $usePumukit1 = false;

    public function __construct(
        DocumentManager $documentManager,
        VideoListService $videoListService,
        NotificationService $notificationService
    ) {
        parent::__construct($documentManager, $videoListService, $notificationService);
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:video:update:pendingstatus')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Update local YouTube status of the pending videos')
            ->setHelp(
                <<<'EOT'
Fast version of pumukit:youtube:update:status

PERFORMANCE NOTE: This command has a fash performance because only use YouTube documents with state STATUS_UPLOADING or STATUS_PROCESSING.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statusArray = [Youtube::STATUS_UPLOADING, Youtube::STATUS_PROCESSING];
        $youtubeDocuments = $this->documentManager->getRepository(Youtube::class)->getWithAnyStatus($statusArray);

        $this->updateVideoStatusInYoutube($youtubeDocuments, $output);

        $this->notificationService->notificationOfUpdatedStatusVideoResults(
            $this->okUpdates,
            $this->failedUpdates,
            $this->errors
        );

        return 0;
    }
}
