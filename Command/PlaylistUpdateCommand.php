<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Services\PlaylistItemInsertService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PlaylistUpdateCommand extends Command
{
    private $documentManager;

    private $playlistItemInsertService;
    private $usePumukit1 = false;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        PlaylistItemInsertService $playlistItemInsertService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->playlistItemInsertService = $playlistItemInsertService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:playlist:update')
            ->setDescription('Update Youtube playlists from Multimedia Objects')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setHelp(
                <<<'EOT'
Command to update playlist in Youtube.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->usePumukit1 = $input->getOption('use-pmk1');

        $multimediaObjects = $this->createYoutubeQueryBuilder()
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute()
        ;

        $infoLog = '[YouTube] Updating playlist for '.count($multimediaObjects).' videos.';
        $this->logger->info($infoLog);
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $response = $this->playlistItemInsertService->updatePlaylist($multimediaObject);
            } catch (\Exception $e) {
                $errorLog = sprintf("[YouTube] Could\\'t update playlist of video %s. Error: %s", $multimediaObject->getId(), $e->getMessage());
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
            }
        }

        return 0;
    }

    private function createYoutubeQueryBuilder()
    {
        $qb = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('properties.origin')->notEqual('youtube')
        ;

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb;
    }
}
