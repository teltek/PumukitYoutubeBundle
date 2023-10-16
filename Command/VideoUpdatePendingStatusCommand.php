<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\VideoListService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VideoUpdatePendingStatusCommand extends VideoUpdateStatusCommand
{
    protected $usePumukit1 = false;

    public function __construct(
        DocumentManager $documentManager,
        VideoListService $videoListService,
        LoggerInterface $logger
    ) {
        parent::__construct($documentManager, $videoListService, $logger);
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

PERFORMANCE NOTE: This command has a fast performance because only use YouTube documents with state STATUS_UPLOADING or STATUS_PROCESSING.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $statusArray = [Youtube::STATUS_UPLOADING, Youtube::STATUS_PROCESSING];
        $youtubeDocuments = $this->documentManager->getRepository(Youtube::class)->getWithAnyStatus($statusArray);
        $infoLog = '[YouTube] Updating (pending) status for '.(is_countable($youtubeDocuments) ? count($youtubeDocuments) : 0).' videos.';
        $this->logger->info($infoLog);
        $this->updateVideoStatusInYoutube($youtubeDocuments, $output);

        return 0;
    }
}
