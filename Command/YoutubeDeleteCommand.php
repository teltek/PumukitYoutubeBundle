<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Broadcast;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Psr\Log\LoggerInterface;

class YoutubeDeleteCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;
    private $broadcastRepo = null;

    private $youtubeService;

    private $okRemoved = array();
    private $failedRemoved = array();
    private $errors = array();

    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:delete')
            ->setDescription('Delete videos from Youtube')
            ->setHelp(<<<EOT
Command to delete controlled videos from Youtube.

EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce("_id", Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        $notPublishedMms = $this->getMultimediaObjectsInYoutubeWithoutStatus($publishedYoutubeIds, MultimediaObject::STATUS_PUBLISHED);
        $this->deleteVideosFromYoutube($notPublishedMms, $output);

        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce("_id", Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        // TODO When tag IMPORTANT is defined as child of PUBLICATION DECISION Tag
        $notImportantMms = $this->getMultimediaObjectsInYoutubeWithoutTagCode($publishedYoutubeIds, 'PUDEAUTO');
        $this->deleteVideosFromYoutube($notImportantMms, $output);

        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce("_id", Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        $notPublicMms = $this->getMultimediaObjectsInYoutubeWithoutBroadcast($publishedYoutubeIds, Broadcast::BROADCAST_TYPE_PUB);
        $this->deleteVideosFromYoutube($notPublicMms, $output);

        $this->checkResultsAndSendEmail();
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository("PumukitSchemaBundle:Tag");
        $this->mmobjRepo = $this->dm->getRepository("PumukitSchemaBundle:MultimediaObject");
        $this->youtubeRepo = $this->dm->getRepository("PumukitYoutubeBundle:Youtube");
        $this->broadcastRepo = $this->dm->getRepository("PumukitSchemaBundle:Broadcast");

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okRemoved = array();
        $this->failedRemoved = array();
        $this->errors = array();

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
    }

    private function deleteVideosFromYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm){
            try{
                $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Started removing video from Youtube of MultimediaObject with id "'.$mm->getId().'"');
                $output->writeln('Started removing video from Youtube of MultimediaObject with id "'.$mm->getId().'"');
                $outDelete = $this->youtubeService->delete($mm);
                if (0 !== $outDelete) {
                    $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] Unknown output in the removal from Youtube of MultimediaObject with id "'.$mm->getId().'"');
                    $output->writeln('Unknown output in the removal from Youtube of MultimediaObject with id "'.$mm->getId().'"');
                }
                $this->okRemoved[] = $mm;
            } catch (\Exception $e) {
                $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] Removal of video from MultimediaObject with id "'.$mm->getId().'" has failed. '.$e->getMessage());
                $output->writeln('Removal of video from MultimediaObject with id "'.$mm->getId().'" has failed. '.$e->getMessage());
                $this->failedRemoved[] = $mm;
                $this->errors[] = substr($e->getMessage(), 0, 100);
            }
        }
    }

    private function getStringIds($mongoIds)
    {
        $stringIds = array();
        foreach ($mongoIds as $mongoId) {
            $stringIds[] = $mongoId->__toString();
        }

        return $stringIds;
    }

    private function getMultimediaObjectsInYoutubeWithoutStatus($youtubeIds, $status)
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->in($youtubeIds)
            ->field('status')->notEqual($status)
            ->getQuery()
            ->execute();
    }

    private function getMultimediaObjectsInYoutubeWithoutTagCode($youtubeIds, $tagCode)
    {
        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->in($youtubeIds)
            ->field('tag.cod')->notEqual($tagCode)
            ->getQuery()
            ->execute();
    }

    private function getMultimediaObjectsInYoutubeWithoutBroadcast($youtubeIds, $broadcastTypeId)
    {
        $mmsNoBroadcast = $this->mmobjRepo->createQueryBuilder()
            ->field('properties.youtube')->in($youtubeIds)
            ->getQuery()
            ->execute();

        $mms = array();
        foreach ($mmsNoBroadcast as $mm) {
            if ($broadcastTypeId !== $mm->getBroadcast()->getBroadcastTypeId()) {
                $mms[] = $mm;
            }
        }

        return $mms;
    }

    private function checkResultsAndSendEmail()
    {
        $youtubeTag = $this->tagRepo->findByCod('PUCHYOUTUBE');
        if (null != $youtubeTag) {
            foreach ($this->okRemoved as $mm){
                if ($mm->containsTagWithCod('PUCHYOUTUBE')) {
                    $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeTag->getId(), false);
                }
            }
            $this->dm->flush();
        }
        $this->youtubeService->sendEmail('remove', $this->okRemoved, $this->failedRemoved, $this->errors);
    }
}