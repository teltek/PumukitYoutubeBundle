<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubePlaylistService;
use Pumukit\YoutubeBundle\Services\YoutubeProcessService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeCheckCommand extends Command
{
    private $documentManager;
    private $tagRepo;
    private $mmobjRepo;
    private $youtubeRepo;
    private $youtubeService;
    private $youtubePlaylistService;
    private $youtubeProcessService;
    private $youtubeTag;
    private $usePumukit1 = false;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        YoutubePlaylistService $youtubePlaylistService,
        YoutubeProcessService $youtubeProcessService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->youtubePlaylistService = $youtubePlaylistService;
        $this->youtubeProcessService = $youtubeProcessService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:check')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Check the YouTube configuration and API status')
            ->setHelp(
                <<<'EOT'
Check:

 - All the accounts configured into in PuMuKIT.
 - The stats of all youtube videos in the platform.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->checkAccounts();
        $output->writeln('<info>* Youtube accounts checked</info>');
        $result = $this->checkMultimediaObjects();
        $output->writeln('<info>* Multimedia object statuses checked</info>');
        $output->writeln(
            sprintf(
                '<info>    %s no_mm, %s from_pmk1, %s error_api, %s ok</info>',
                $result['no_mm'],
                $result['from_pmk1'],
                $result['error_api'],
                $result['ok']
            )
        );

        return 0;
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->tagRepo = $this->documentManager->getRepository(Tag::class);
        $this->mmobjRepo = $this->documentManager->getRepository(MultimediaObject::class);
        $this->youtubeRepo = $this->documentManager->getRepository(Youtube::class);

        $this->youtubeTag = $this->tagRepo->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        if (!$this->youtubeTag) {
            throw new \Exception('No tag with code YOUTUBE');
        }

        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    private function checkMultimediaObjects(): array
    {
        $result = [
            'no_mm' => 0,
            'from_pmk1' => 0,
            'error_api' => 0,
            'ok' => 0,
        ];

        $statusArray = [Youtube::STATUS_REMOVED, Youtube::STATUS_DUPLICATED];
        $youtubes = $this->youtubeRepo->getWithoutAnyStatus($statusArray);

        foreach ($youtubes as $youtube) {
            $multimediaObject = $this->findByYoutubeIdAndPumukit1Id($youtube, false);
            if (null == $multimediaObject) {
                $multimediaObject = $this->findByYoutubeId($youtube);
                if (null == $multimediaObject) {
                    ++$result['no_mm'];
                } else {
                    ++$result['from_pmk1'];
                }

                continue;
            }

            $processResult = $this->youtubeProcessService->getData(
                'status',
                $youtube->getYoutubeId(),
                $youtube->getYoutubeAccount()
            );

            if ($processResult['error']) {
                ++$result['error_api'];
            } else {
                ++$result['ok'];
            }
        }

        return $result;
    }

    private function checkAccounts(): void
    {
        foreach ($this->youtubeTag->getChildren() as $account) {
            $login = $account->getProperty('login');
            if (!$login) {
                throw new \Exception(sprintf('Youtube account tag %s without login property', $account->getCod()));
            }

            try {
                $this->youtubePlaylistService->getAllYoutubePlaylists($login);
            } catch (\Exception $e) {
                throw new \Exception(sprintf('Error getting playlist of account %s. To debug it execute `python getAllPlaylists.py --account %s`', $login, $login));
            }
        }
    }

    private function findByYoutubeIdAndPumukit1Id(Youtube $youtube, $pumukit1Id = false)
    {
        $qb = $this->mmobjRepo
            ->createQueryBuilder()
            ->field('properties.youtube')
            ->equals($youtube->getId())
            ->field('properties.origin')
            ->notEqual('youtube')
        ;

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')
                ->exists($pumukit1Id)
            ;
        }

        return $qb
            ->getQuery()
            ->getSingleResult()
        ;
    }

    private function findByYoutubeId(Youtube $youtube)
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->equals($youtube->getId())
            ->getQuery()
            ->getSingleResult()
        ;
    }
}
