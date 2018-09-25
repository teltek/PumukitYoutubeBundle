<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeDeleteCommand extends ContainerAwareCommand
{
    const PUB_CHANNEL_WEBTV = 'PUCHWEBTV';
    const PUB_CHANNEL_YOUTUBE = 'PUCHYOUTUBE';
    const PUB_DECISION_AUTONOMOUS = 'PUDEAUTO';

    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;

    private $youtubeService;
    private $tagService;

    private $okRemoved = array();
    private $failedRemoved = array();
    private $errors = array();

    private $usePumukit1 = false;

    private $logger;

    private $syncStatus;

    protected function configure()
    {
        $this
            ->setName('youtube:delete')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Delete videos from Youtube')
            ->setHelp(
                <<<'EOT'
Command to delete controlled videos from Youtube.

EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        if ($this->syncStatus) {
            $status = array(MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN);
        } else {
            $status = array(MultimediaObject::STATUS_PUBLISHED);
        }
        $notPublishedMms = $this->getMultimediaObjectsInYoutubeWithoutStatus($publishedYoutubeIds, $status);
        if (0 != count($notPublishedMms)) {
            $output->writeln('Removing '.count($notPublishedMms).' object(s) with status not published');
            $this->deleteVideosFromYoutube($notPublishedMms, $output);
        }

        $arrayPubTags = $this->getContainer()->getParameter('pumukit_youtube.pub_channels_tags');
        foreach ($arrayPubTags as $tagCode) {
            $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
            $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
            // TODO When tag IMPORTANT is defined as child of PUBLICATION DECISION Tag
            $notCorrectTagMms = $this->getMultimediaObjectsInYoutubeWithoutTagCode($publishedYoutubeIds, $tagCode);
            if (0 != count($notCorrectTagMms)) {
                $output->writeln('Removing '.count($notCorrectTagMms).' object(s) w/o tag '.$tagCode);
                $this->deleteVideosFromYoutube($notCorrectTagMms, $output);
            }
        }

        $youtubeMongoIds = $this->youtubeRepo->getDistinctFieldWithStatusAndForce('_id', Youtube::STATUS_PUBLISHED, false);
        $publishedYoutubeIds = $this->getStringIds($youtubeMongoIds);
        $notPublicMms = $this->getMultimediaObjectsInYoutubeWithoutEmbeddedBroadcast($publishedYoutubeIds, 'public');
        if (0 != count($notPublicMms)) {
            $output->writeln('Removing '.count($notPublicMms).' object(s) with broadcast not public');
            $this->deleteVideosFromYoutube($notPublicMms, $output);
        }

        $orphanYoutubes = $this->youtubeRepo->findByStatus(Youtube::STATUS_TO_DELETE);
        if (0 != count($orphanYoutubes)) {
            $output->writeln('Removing '.count($orphanYoutubes).' orphanYoutube(s) ');
            $this->deleteOrphanVideosFromYoutube($orphanYoutubes, $output);
        }

        $this->checkResultsAndSendEmail();
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');
        $this->syncStatus = $this->getContainer()->getParameter('pumukit_youtube.sync_status');
        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');
        $this->tagService = $this->getContainer()->get('pumukitschema.tag');

        $this->okRemoved = array();
        $this->failedRemoved = array();
        $this->errors = array();

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');

        $this->usePumukit1 = $input->getOption('use-pmk1');
    }

    private function deleteVideosFromYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                  .'] Started removing video from Youtube of MultimediaObject with id "'
                  .$mm->getId().'"';
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $outDelete = $this->youtubeService->delete($mm);
                if (0 !== $outDelete) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                      .'] Unknown error in the removal from Youtube of MultimediaObject with id "'
                      .$mm->getId().'": '.$outDelete;
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedRemoved[] = $mm;
                    $this->errors[] = $errorLog;
                    continue;
                }
                $this->okRemoved[] = $mm;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  .'] Removal of video from MultimediaObject with id "'.$mm->getId()
                  .'" has failed. '.$e->getMessage();
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $mm;
                $this->errors[] = $e->getMessage();
            }
        }
    }

    private function deleteOrphanVideosFromYoutube($orphanYoutubes, OutputInterface $output)
    {
        foreach ($orphanYoutubes as $youtube) {
            try {
                $infoLog = __CLASS__.' ['.__FUNCTION__
                  .'] Started removing orphan video from Youtube with id "'
                  .$youtube->getId().'"';
                $this->logger->addInfo($infoLog);
                $output->writeln($infoLog);
                $outDelete = $this->youtubeService->deleteOrphan($youtube);
                if (0 !== $outDelete) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                      .'] Unknown error in the removal from Youtube id "'
                      .$youtube->getId().'": '.$outDelete;
                    $this->logger->addError($errorLog);
                    $output->writeln($errorLog);
                    $this->failedRemoved[] = $youtube;
                    $this->errors[] = $errorLog;
                    continue;
                }

                $youtubeTag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => 'YOUTUBE'));
                $multimediaObject = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject')->findOneBy(array('_id' => new \MongoId($youtube->getMultimediaObjectId())));
                if ($multimediaObject) {
                    foreach ($multimediaObject->getTags() as $embeddedTag) {
                        $tag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('_id' => new \MongoId($embeddedTag->getId())));
                        if ($tag->getParent() && $youtubeTag->getId() === $tag->getParent()->getId()) {
                            $youtube->setYoutubeAccount($tag->getProperty('login'));
                            $youtube->setStatus(Youtube::STATUS_UPLOADING);
                            $multimediaObject->removeProperty('youtube');
                            $multimediaObject->removeProperty('youtubeurl');
                            $this->dm->flush();
                        }
                    }
                }

                $this->okRemoved[] = $youtube;
            } catch (\Exception $e) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  .'] Removal of video from Youtube with id "'.$youtube->getId()
                  .'" has failed. '.$e->getMessage();
                $this->logger->addError($errorLog);
                $output->writeln($errorLog);
                $this->failedRemoved[] = $youtube;
                $this->errors[] = $e->getMessage();
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
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('status')->notIn($status)
            ->getQuery()
            ->execute();
    }

    private function getMultimediaObjectsInYoutubeWithoutTagCode($youtubeIds, $tagCode)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('tags.cod')->notEqual($tagCode)
            ->getQuery()
            ->execute();
    }

    private function getMultimediaObjectsInYoutubeWithoutEmbeddedBroadcast($youtubeIds, $broadcastTypeId)
    {
        return $this->createYoutubeQueryBuilder($youtubeIds)
            ->field('embeddedBroadcast.type')->notEqual('public')
            ->getQuery()
            ->execute();
    }

    private function createYoutubeQueryBuilder($youtubeIds = array())
    {
        $qb = $this->mmobjRepo
           ->createQueryBuilder()
           ->field('properties.youtube')->in($youtubeIds)
           ->field('properties.origin')->notEqual('youtube');

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')->exists(false);
        }

        return $qb;
    }

    private function checkResultsAndSendEmail()
    {
        $youtubeTag = $this->tagRepo->findByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            foreach ($this->okRemoved as $mm) {
                if ($mm instanceof MultimediaObject) {
                    $youtubeDocument = $this->dm->getRepository('PumukitYoutubeBundle:Youtube')->findOneBy(array('status' => Youtube::STATUS_REMOVED));
                    if ($mm->containsTagWithCod(self::PUB_CHANNEL_YOUTUBE) && $youtubeDocument) {
                        $this->tagService->removeTagFromMultimediaObject($mm, $youtubeTag->getId(), false);
                    }
                }
            }
            $this->dm->flush();
        }
        if (!empty($this->okRemoved) || !empty($this->failedRemoved)) {
            $this->youtubeService->sendEmail('remove', $this->okRemoved, $this->failedRemoved, $this->errors);
        }
    }
}
