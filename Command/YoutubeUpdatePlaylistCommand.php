<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\PlaylistItemInsertService;
use Pumukit\YoutubeBundle\Services\YoutubePlaylistService;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdatePlaylistCommand extends Command
{
    private $documentManager;

    private $playlistItemInsertService;
    private $tagRepo;
    private $mmobjRepo;
    private $youtubeRepo;
    private $youtubeService;
    private $okUpdates = [];
    private $failedUpdates = [];
    private $errors = [];
    private $usePumukit1 = false;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        PlaylistItemInsertService $playlistItemInsertService,
        YoutubeService $youtubeService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->playlistItemInsertService = $playlistItemInsertService;
        $this->youtubeService = $youtubeService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:update:playlist')
            ->setDescription('Update Youtube playlists from Multimedia Objects')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List actions')
            ->setHelp(
                <<<'EOT'
Command to update playlist in Youtube.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $multimediaObjects = $this->createYoutubeQueryBuilder()
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute()
        ;

        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $response = $this->playlistItemInsertService->updatePlaylist($multimediaObject);

                exit;
                // $outUpdatePlaylists = $this->youtubePlaylistService->updatePlaylists($multimediaObject);
                if (0 !== $outUpdatePlaylists) {
                    $errorLog = sprintf('%s [%s] Unknown error in the update of Youtube Playlists of MultimediaObject with id %s: %s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $outUpdatePlaylists);
                    $this->logger->error($errorLog);
                    $output->writeln($errorLog);
                    $this->failedUpdates[] = $multimediaObject;
                    $this->errors[] = $errorLog;

                    continue;
                }
                $infoLog = sprintf('%s [%s] Updated all playlists of MultimediaObject with id %s', __CLASS__, __FUNCTION__, $multimediaObject->getId());
                $this->logger->info($infoLog);
                $output->writeln($infoLog);
                $this->okUpdates[] = $multimediaObject;
            } catch (\Exception $e) {
                $errorLog = sprintf('%s [%s] Error: Couldn\'t update playlists of MultimediaObject with id %s [Exception]:%s', __CLASS__, __FUNCTION__, $multimediaObject->getId(), $e->getMessage());
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
                $this->failedUpdates[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
            }
        }
        $this->checkResultsAndSendEmail();

        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->tagRepo = $this->documentManager->getRepository(Tag::class);
        $this->mmobjRepo = $this->documentManager->getRepository(MultimediaObject::class);
        $this->youtubeRepo = $this->documentManager->getRepository(Youtube::class);
        $this->okUpdates = [];
        $this->failedUpdates = [];
        $this->errors = [];
        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    private function isPuMuKITMaster(): bool
    {
        // 'playlists_master'

        return true;
    }

    private function getAllYouTubeAccounts(): array
    {
        return $this->documentManager->getRepository(Tag::class)->findBy([
            'properties.login' => ['$exists' => true],
        ]);
    }

    private function createYoutubeQueryBuilder()
    {
        $qb = $this->mmobjRepo->createQueryBuilder()
            ->field('properties.origin')->notEqual('youtube');

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb;
    }

    private function checkResultsAndSendEmail(): void
    {
        if (!empty($this->errors)) {
            $this->youtubeService->sendEmail('playlist update', $this->okUpdates, $this->failedUpdates, $this->errors);
        }
    }
}
