<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeImportVideoCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $seriesRepo = null;
    private $youtubeRepo = null;

    private $youtubeService;
    private $factoryService;
    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:import:video')
            ->setDescription('Create a multimedia object from Youtube')
            ->addArgument('yid', InputArgument::REQUIRED, 'YouTube ID')
            ->addArgument('series', InputArgument::OPTIONAL, 'Series id where the object is created')
            ->addOption('step', 'S', InputOption::VALUE_REQUIRED, 'Step of the importation. See help for more info', -99)
            ->setHelp(<<<EOT
Command to create a multimedia object from Youtube.

Steps:
 * 1.- Create the Multimedia Object.
 * 2.- Download the image
 * 3.- Download/move the tracks

Example:
  <info>php bin/console youtube:import:video --env=prod --step=1 XXXXXYYYY</info>

EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        $yid = $input->getArgument('yid');
        $step = $input->getOption('step');
        switch ($step) {
        case 1:
            //Check if exists
            if ($this->getMmObjFromYid($yid)) {
                $output->writeln('<error>Already exists a mmobj from Youtube video with id ' . $yid .'</error>');
                return false;
            }

            $series = $this->getSeries($input->getArgument('series'));
            $output->writeln(sprintf(' * Creating multimedia object from id %s in series %s', $yid, $series->getId()));
            $this->createMultimediaObject($yid, $series, $output);
            break;
        case 2:
            $output->writeln(' * TODO ');
            break;
        case 3:
            $output->writeln(' * TODO ');
            break;
        default:
            $output->writeln('<error>Select a valid step</error>');
        }
    }


    private function createMultimediaObject($yid, Series $series, OutputInterface $output)
    {
        try {
            $meta = $this->youtubeService->getVideoMeta($yid);
        } catch (\Exception $e) {
            $output->writeln('<error>No Youtube video with id ' . $yid .'</error>');
            return false;
        }

        //Create using the factory
        $mmobj = $this->factoryService->createMultimediaObject($series, false);
        $mmobj->setTitle($meta['out']['snippet']['title']);
        if (isset($meta['out']['snippet']['description'])) {
            $mmobj->setDescription($meta['out']['snippet']['description']);
        }
        if (isset($meta['out']['snippet']['tags'])) {
            $mmobj->setKeywords($meta['out']['snippet']['tags']);
        }
        $dataTime = \DateTime::createFromFormat('Y-m-d\TH:i:s', substr($meta['out']['snippet']['publishedAt'], 0, 19));
        $mmobj->setRecordDate($dataTime);
        $mmobj->setPublicDate($dataTime);
        $mmobj->setProperty('origin', 'youtube');
        $mmobj->setProperty('youtubemeta', $meta['out']);

        $this->dm->persist($mmobj);
        $this->dm->flush();
    }



    private function getMmObjFromYid($yid)
    {
        $mmobj = $this->mmobjRepo->findOneBy(array('properties.youtubemeta.id' => $yid));
        if ($mmobj) {
            return $mmobj;
        }


        $yt = $this->youtubeRepo
            ->createQueryBuilder()
            ->field('youtubeId')->equals($yid)
            ->getQuery()
            ->getSingleResult();

        if (!$yt) {
            return null;
        }

        return $this->mmobjRepo->find($yt->getMultimediaObjectId());
    }


    private function getSeries($seriesId)
    {
        if (!$seriesId) {
            throw new \Exception('No series id argument');
        }

        $series = $this->seriesRepo->find($seriesId);
        if ($series) {
            $this->logger->info(sprintf("Using series with id %s", $seriesId));
            return $series;
        }


        $series = $this->seriesRepo->findOneBy(array('properties.origin' => 'youtube', 'properties.fromyoutubetag' => $seriesId));
        if ($series) {
            $this->logger->info(sprintf("Using series with YouTube property %s", $seriesId));
            return $series;
        }

        //tag with youtube
        $tag = $this->tagRepo->findOneBy(array('properties.origin' => 'youtube', 'properties.youtube' => $seriesId));
        if ($tag) {
            $this->logger->info(sprintf("Creating series from YouTube property %s", $seriesId));
            $series = $this->factoryService->createSeries();
            $series->setI18nTitle($tag->getI18nTitle());
            $series->setProperty('origin', 'youtube');
            $series->setProperty('fromyoutubetag', $seriesId);

            $this->dm->persist($series);
            $this->dm->flush();

            return $series;

        }


        throw new \Exception('No series, or YouTube tag with id '. $seriesId);
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->seriesRepo = $this->dm->getRepository('PumukitSchemaBundle:Series');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');
        $this->factoryService = $this->getContainer()->get('pumukitschema.factory');

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
    }
}