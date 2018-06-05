<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeUpdatePendingStatusCommand extends YoutubeUpdateStatusCommand
{
    protected function configure()
    {
        $this
            ->setName('youtube:update:pendingstatus')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Update local YouTube status of the pending videos')
            ->setHelp(
                <<<'EOT'
Fast version of youtube:update:status

PERFORMANCE NOTE: This command has a fash performance because only use YouTube documents with state STATUS_UPLOADING or STATUS_PROCESSING.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $statusArray = array(Youtube::STATUS_UPLOADING, Youtube::STATUS_PROCESSING);
        $youtubes = $this->youtubeRepo->getWithAnyStatus($statusArray);

        $this->updateVideoStatusInYoutube($youtubes, $output);
        $this->checkResultsAndSendEmail();
    }
}