<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Repository\MultimediaObjectRepository;
use Pumukit\SchemaBundle\Repository\TagRepository;
use Pumukit\YoutubeBundle\Repository\YoutubeRepository;
use Pumukit\YoutubeBundle\Services\YoutubePlaylistService;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdatePlaylistCommand extends ContainerAwareCommand
{
    /**
     * @var DocumentManager
     */
    private $dm;
    /**
     * @var TagRepository
     */
    private $tagRepo;
    /**
     * @var MultimediaObjectRepository
     */
    private $mmobjRepo;
    /**
     * @var YoutubeRepository
     */
    private $youtubeRepo;
    /**
     * @var YoutubeService
     */
    private $youtubeService;
    /**
     * @var YoutubePlaylistService
     */
    private $youtubePlaylistService;

    private $okUpdates = [];
    private $failedUpdates = [];
    private $errors = [];

    private $usePumukit1 = false;
    /**
     * @var LoggerInterface
     */
    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:update:playlist')
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

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     * @throws \Exception
     *
     * @return null|int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = (true === $input->getOption('dry-run'));

        $multimediaObjects = $this->createYoutubeQueryBuilder()
            ->field('properties.youtube')->exists(true)
            ->getQuery()
            ->execute()
        ;

        $this->youtubePlaylistService->syncPlaylistsRelations($dryRun);

        if ($dryRun) {
            return;
        }

        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $outUpdatePlaylists = $this->youtubePlaylistService->updatePlaylists($multimediaObject);
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
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->tagRepo = $this->dm->getRepository(Tag::class);
        $this->mmobjRepo = $this->dm->getRepository(MultimediaObject::class);
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');
        $this->youtubePlaylistService = $this->getContainer()->get('pumukityoutube.youtube_playlist');

        $this->okUpdates = [];
        $this->failedUpdates = [];
        $this->errors = [];

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');

        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    /**
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    private function createYoutubeQueryBuilder()
    {
        $qb = $this->mmobjRepo->createQueryBuilder()
            ->field('properties.origin')->notEqual('youtube');

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb;
    }

    private function checkResultsAndSendEmail()
    {
        if (!empty($this->errors)) {
            $this->youtubeService->sendEmail('playlist update', $this->okUpdates, $this->failedUpdates, $this->errors);
        }
    }
}
