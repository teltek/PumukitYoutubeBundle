<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdatePendingStatusCommand extends YoutubeUpdateStatusCommand
{
    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:update:pendingstatus')
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
        $youtubeDocuments = $this->youtubeRepo->getWithAnyStatus($statusArray);

        $this->updateVideoStatusInYoutube($youtubeDocuments, $output);
        $this->checkResultsAndSendEmail();

        return 0;
    }
}
