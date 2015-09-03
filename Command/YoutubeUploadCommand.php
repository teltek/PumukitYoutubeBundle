<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Psr\Log\LoggerInterface;

class YoutubeUploadCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;
    private $broadcastRepo = null;

    private $logger;
    private $youtubeService;
    private $senderService;
    private $translator;
    private $router;

    private $okUploads = array();
    private $failedUploads = array();
    private $errors = array();

    protected function configure()
    {
        $this
            ->setName('youtube:upload')
            ->setDescription('Upload videos from Multimedia Objects to Youtube')
            ->setHelp(<<<EOT
Command to upload a controlled videos to Youtube.

EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        $newMultimediaObjects = $this->getNewMultimediaObjectsToUpload();
        $this->uploadVideosToYoutube($newMultimediaObjects, $output);

        $errorStatus = array(
                             Youtube::STATUS_HTTP_ERROR,
                             Youtube::STATUS_ERROR,
                             Youtube::STATUS_UPDATE_ERROR
                             );
        $failureMultimediaObjects = $this->getUploadsByStatus($errorStatus);
        $this->uploadVideosToYoutube($failureMultimediaObjects, $output);

        $removedStatus = array(Youtube::STATUS_REMOVED);
        $removedYoutubeMultimediaObjects = $this->getUploadsByStatus($removedStatus);
        $this->uploadVideosToYoutube($removedYoutubeMultimediaObjects, $output);

        $this->checkResultsAndSendEmail();
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository("PumukitSchemaBundle:Tag");
        $this->mmobjRepo = $this->dm->getRepository("PumukitSchemaBundle:MultimediaObject");
        $this->youtubeRepo = $this->dm->getRepository("PumukitYoutubeBundle:Youtube");
        $this->broadcastRepo = $this->dm->getRepository("PumukitSchemaBundle:Broadcast");

        $container = $this->getContainer();
        $this->youtubeService = $container->get('pumukityoutube.youtube');
        $this->senderService = $container->get('pumukit_notification.sender');
        $this->translator = $container->get('translator');
        $this->router = $container->get('router');
        $this->logger = $container->get('monolog.logger.youtube');

        $this->okUploads = array();
        $this->failedUploads = array();
        $this->errors = array();
    }

    private function uploadVideosToYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            $playlistTagId = $this->getPlaylistTagId($mm, $output);

            try {
                $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Started uploading to Youtube of MultimediaObject with id "'.$mm->getId().'"');
                $output->writeln('Started uploading to Youtube of MultimediaObject with id "'.$mm->getId().'"');
                $outUpload = $this->youtubeService->upload($mm, 27, 'public', false);
                if (0 !== $outUpload) {
                    $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] Error in the upload to Youtube of MultimediaObject with id "'.$mm->getId().'": ' . $outUpload);
                    $output->writeln('Error in the upload to Youtube of MultimediaObject with id "'.$mm->getId().'": ' . $outUpload);
                    $this->failedUploads[] = $mm;
                    $this->errors[] = $outUpload;
                    continue;
                }
                if ($playlistTagId) {
                    $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Started moving video to Youtube playlist assign with Tag id "'.$playlistTagId.'" of MultimediaObject with id "'.$mm->getId().'"');
                    $output->writeln('Started moving video to Youtube playlist assign with Tag id "'.$playlistTagId.'" of MultimediaObject with id "'.$mm->getId().'"');
                    $outMoveToList = $this->youtubeService->moveToList($mm, $playlistTagId);
                    if (0 !== $outMoveToList) {
                        $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] Unknown out in the move list to Youtube of MultimediaObject with id "'.$mm->getId().'"');
                        $output->writeln('Error in the move list to Youtube of MultimediaObject with id "'.$mm->getId().'": ' . $outMoveToList);
                        $this->failedUploads[] = $mm;
                        $this->errors[] = $outMoveToList;
                        continue;
                    }
                }
                $this->okUploads[] = $mm;
            } catch (\Exception $e) {
                $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] The upload of the video from the Multimedia Object with id "'.$mm->getId().'" failed: '.$e->getMessage());
                $output->writeln('The upload of the video from the Multimedia Object with id "'.$mm->getId().'" failed: '.$e->getMessage());
                $this->failedUploads[] = $mm;
                $this->errors[] = substr($e->getMessage(), 0, 100);
            }
        }
    }

    private function createMultimediaObjectsToUploadQueryBuilder()
    {
        $publicBroadcast = $this->broadcastRepo->findPublicBroadcast();

        return $this->mmobjRepo->createQueryBuilder()
          ->field('properties.pumukit1id')->exists(false)
          ->field('status')->equals(MultimediaObject::STATUS_PUBLISHED)
          ->field('broadcast')->references($publicBroadcast)
          /* ->field('tags.cod')->equals('IMPORTANT') TODO When Tag with code 'IMPORTANT' is done ('autónomo' in Pumukit1.8) */ // TODO review !!!!!!!!!!!
          ->field('tags.cod')->all(array('PUCHYOUTUBE', 'PUDEAUTO'));
    }

    private function getNewMultimediaObjectsToUpload()
    {
        return $this->createMultimediaObjectsToUploadQueryBuilder()
          ->field('properties.youtube')->exists(false)
          ->getQuery()
          ->execute();
    }

    private function getUploadsByStatus($statusArray=array())
    {
        $mmIds = $this->youtubeRepo->getDistinctMultimediaObjectIdsWithAnyStatus($statusArray);

        return $this->createMultimediaObjectsToUploadQueryBuilder()
          ->field('_id')->in($mmIds->toArray())
          ->getQuery()
          ->execute();
    }

    private function getPlaylistTagId($mm, OutputInterface $output)
    {
        $playlistTagId = null;
        $embedTag = null;
        foreach ($mm->getTags() as $tag) {
            if ((0 === strpos($tag->getPath(), "ROOT|YOUTUBE|")) && ("YOUTUBE" !== $tag->getCod()) {
                $embedTag = $tag;
                break;
            }
        }
        if (null != $embedTag) {
            $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());
            if (null != $playlistTag) {
                $playlistTagId = $playlistTag->getId();
            } else {
                $output->writeln('MultimediaObject with id "'.$mm->getId().'" does have an EmbedTag with path "'.$embedTag->getPath().'" and code "'.$embedTag->getCod().'" but does not exist in Tag repository');
            }
        } else {
            $output->writeln('MultimediaObject with id "'.$mm->getId().'" does not have any EmbedTag with path starting with "ROOT|YOUTUBE|" so we search for Tag with code "YOUTUBECONFERENCES" as default Youtube playlist.');
            $playlistTag = $this->tagRepo->findOneByCod('YOUTUBECONFERENCES');
            if (!$playlistTag) {
                $rootTag = $this->tagRepo->findOneByCod('ROOT');
                $playlistTag = new Tag();
                $playlistTag->setCod('YOUTUBECONFERENCES');
                $playlistTag->setParent($rootTag);
                $playlistTag->setMetatag(false);
                $playlistTag->setDisplay(true);
                $playlistTag->setTitle('Conferences', 'en');
                $this->dm->persist($playlistTag);
                $this->dm->flush();
                $output->writeln('There is no Tag with code "YOUTUBECONFERENCES" as default Youtube playlist so we created it with resultant id "'.$playlistTag->getId().'".');
            }
            $playlistTagId = $playlistTag->getId();
        }

        return $playlistTagId;
    }

    private function checkResultsAndSendEmail()
    {
        $youtubeTag = $this->tagRepo->findByCod('PUCHYOUTUBE');
        if (null != $youtubeTag) {
            foreach ($this->okUploads as $mm){
                if (!$mm->containsTagWithCod('PUCHYOUTUBE')) {
                    $addedTags = $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId(), false);
                }
            }
            $this->dm->flush();
        }
        if (!empty($this->okUploads) || !empty($this->failedUploads)) {
            $this->youtubeService->sendEmail('upload', $this->okUploads, $this->failedUploads, $this->errors);
        }
    }
}