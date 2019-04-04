<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeCheckCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;

    private $youtubeService;
    private $youtubeProcessService;
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkAccounts();
        $output->writeln('<info>* Youtube accounts checked</info>');
        $result = $this->checkMultimediaObjects();
        $output->writeln('<info>* Multimedia object statuses checked</info>');
        $output->writeln(
            sprintf(
                '<info>    %s no_mm, %s from_pmk1, %s error_api, %s ok</info>',
                $result['no_mm'], $result['from_pmk1'], $result['error_api'], $result['ok'])
        );
    }

    private function checkMultimediaObjects()
    {
        $result = array(
            'no_mm' => 0,
            'from_pmk1' => 0,
            'error_api' => 0,
            'ok' => 0,
        );

        $statusArray = array(Youtube::STATUS_REMOVED, Youtube::STATUS_NOTIFIED_ERROR, Youtube::STATUS_DUPLICATED);
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

    private function checkAccounts()
    {
        foreach ($this->youtubeTag->getChildren() as $account) {
            $login = $account->getProperty('login');
            if (!$login) {
                throw new \Exception(sprintf('Youtube account tag %s without login property', $account->getCod()));
            }
            try {
                $this->youtubeService->getAllYoutubePlaylists($login);
            } catch (\Exception $e) {
                throw new \Exception(sprintf('Error getting playlist of account %s. To debug it execute `python getAllPlaylists.py --account %s`', $login, $login));
            }
        }
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $this->youtubeTag = $this->tagRepo->findOneBy(array('cod' => 'YOUTUBE'));
        if (!$this->youtubeTag) {
            throw new \Exception('No tag with code YOUTUBE');
        }

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');
        $this->youtubeProcessService = $this->getContainer()->get('pumukityoutube.youtubeprocess');

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');

        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    private function findByYoutubeIdAndPumukit1Id(Youtube $youtube, $pumukit1Id = false)
    {
        $qb = $this->mmobjRepo
            ->createQueryBuilder()
            ->field('properties.youtube')
            ->equals($youtube->getId())
            ->field('properties.origin')
            ->notEqual('youtube');

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')
                ->exists($pumukit1Id);
        }

        return $qb
            ->getQuery()
            ->getSingleResult();
    }

    private function findByYoutubeId(Youtube $youtube)
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->equals($youtube->getId())
            ->getQuery()
            ->getSingleResult();
    }
}
