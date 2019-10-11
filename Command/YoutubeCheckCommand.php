<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Repository\MultimediaObjectRepository;
use Pumukit\SchemaBundle\Repository\TagRepository;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Repository\YoutubeRepository;
use Pumukit\YoutubeBundle\Services\YoutubePlaylistService;
use Pumukit\YoutubeBundle\Services\YoutubeProcessService;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeCheckCommand extends ContainerAwareCommand
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
    /**
     * @var YoutubeProcessService
     */
    private $youtubeProcessService;
    /**
     * @var Tag
     */
    private $youtubeTag;
    private $usePumukit1 = false;
    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:check')
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

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     *
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
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
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->tagRepo = $this->dm->getRepository(Tag::class);
        $this->mmobjRepo = $this->dm->getRepository(MultimediaObject::class);
        $this->youtubeRepo = $this->dm->getRepository(Youtube::class);

        $this->youtubeTag = $this->tagRepo->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        if (!$this->youtubeTag) {
            throw new \Exception('No tag with code YOUTUBE');
        }

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');
        $this->youtubePlaylistService = $this->getContainer()->get('pumukityoutube.youtube_playlist');
        $this->youtubeProcessService = $this->getContainer()->get('pumukityoutube.youtubeprocess');

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');

        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    /**
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     * @throws \Exception
     *
     * @return array
     */
    private function checkMultimediaObjects()
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

    /**
     * @throws \Exception
     */
    private function checkAccounts()
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

    /**
     * @param Youtube $youtube
     * @param bool    $pumukit1Id
     *
     * @return array|object|null
     */
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

    /**
     * @param Youtube $youtube
     *
     * @return array|object|null
     */
    private function findByYoutubeId(Youtube $youtube)
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->equals($youtube->getId())
            ->getQuery()
            ->getSingleResult()
        ;
    }
}
