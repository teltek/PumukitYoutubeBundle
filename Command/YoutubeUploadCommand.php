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

    private $youtubeService;

    private $okUploads = array();
    private $failedUploads = array();
    private $errors = array();
    private $correct = false;
    private $failure = false;

    private $logger;

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

        $failureMultimediaObjects = $this->getFailureUploads();
        $this->uploadVideosToYoutube($failureMultimediaObjects, $output);
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

        $this->okUploads = array();
        $this->failedUploads = array();
        $this->errors = array();
        $this->correct = false;
        $this->failure = false;

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
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
                    $output->writeln('Unknown out in the upload to Youtube of MultimediaObject with id "'.$mm->getId().'"');
                }
                if ($playlistTagId) {
                    $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Started moving video to Youtube playlist assign with Tag id "'.$playlistTagId.'" of MultimediaObject with id "'.$mm->getId().'"');
                    $output->writeln('Started moving video to Youtube playlist assign with Tag id "'.$playlistTagId.'" of MultimediaObject with id "'.$mm->getId().'"');
                    $outMoveToList = $this->youtubeService->moveToList($mm, $playlistTagId);
                    if (0 !== $outMoveToList) {
                        $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] Unknown out in the move list to Youtube of MultimediaObject with id "'.$mm->getId().'"');
                        $output->writeln('Unknown out in the move list to Youtube of MultimediaObject with id "'.$mm->getId().'"');
                    }
                }
                $this->okUploads[] = $mm;
                $this->correct = true;
            } catch (\Exception $e) {
                $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] The upload of the video from the Multimedia Object with id "'.$mm->getId().'" failed: '.$e->getMessage());
                $output->writeln('The upload of the video from the Multimedia Object with id "'.$mm->getId().'" failed: '.$e->getMessage());
                $this->failedUploads[] = $mm;
                $this->errors[] = substr($e->getMessage(), 0, 100);
                $this->failure = true;
            }
        }
        $this->checkResultsAndSendEmail();
    }

    private function getNewMultimediaObjectsToUpload()
    {
        $publicBroadcast = $this->broadcastRepo->findPublicBroadcast();

        $mms = $this->mmobjRepo->createQueryBuilder()
          ->field('status')->equals(MultimediaObject::STATUS_PUBLISHED)
          ->field('broadcast')->references($publicBroadcast)
          /* ->field('tags.cod')->equals('IMPORTANT') TODO When Tag with code 'IMPORTANT' is done ('autónomo' in Pumukit1.8) */
          ->field('tags.cod')->equals('PUDEAUTO')
          ->field('properties.youtube')->exists(false)
          ->getQuery()
          ->execute();

        return $mms;
    }

    private function getFailureUploads()
    {
        $errorStatus = array(
                             Youtube::STATUS_HTTP_ERROR,
                             Youtube::STATUS_ERROR,
                             Youtube::STATUS_UPDATE_ERROR
                             );
        $mmIds = $this->youtubeRepo->getWithAnyStatus($errorStatus);

        $mms = $this->mmobjRepo->createQueryBuilder()
          ->field('_id')->in($mmIds->toArray());

        return $mms;
    }

    private function getPlaylistTagId($mm, OutputInterface $output)
    {
        $playlistTagId = null;
        $embedTag = null;
        foreach ($mm->getTags() as $tag) {
            if (0 === strpos($tag->getPath(), "ROOT|YOUTUBE|")) {
                $embedTag = $tag;
                break;
            }
        }
        if ($embedTag) {
            $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());
            if ($playlistTag) {
                $playlistTagId = $playlistTag->getId();
            } else {
                $output->writeln('MultimediaObject with id "'.$mm->getId().'" does have an EmbedTag with path "'.$embedTag->getPath().'" and code "'.$embedTag->getCod().'" but does not exist in Tag repository');
            }
        } else {
            // TODO: Change "YOUTUBECONFERENCES" to "YT5" as default playlist tag code in CMAR.
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
        if ($this->correct){
            $this->sendEmail($this->okUploads, "Ok");
            $this->correct = false;
            $youtubeTag = $this->tagRepo->findByCod('PUCHYOUTUBE');
            if ($youtubeTag) {
                foreach ($this->okUploads as $mm){
                    if (!$mm->containsTagWithCod('PUCHYOUTUBE')) {
                        $mm->addTag($youtubeTag);
                        $this->dm->persist($mm);
                    }
                }
                $this->dm->flush();
            }
            $this->okUploads = array();
        }
        if ($this->failure){
            $this->sendEmail($this->failedUploads, "Error", $this->errors);
            $this->failure = false;
            $this->failedUploads = array();
        }
    }

    function sendEmail($mms, $cause, $errors = null)
    {
        $container = $this->getContainer();
        $senderService = $container->get('pumukit_notification.sender');
        $translator = $container->get('translator');
        $router = $container->get('router');
        //$mail->addAddresses(array('rubenrua@teltek.es', 'nacho.seijo@teltek.es', 'luispena@teltek.es'));
        $emailTo = $this->senderService->getSenderEmail();
        $subject = $this->translator->trans('YouTube upload videos status');

        if ($cause == "Ok"){
            $body = $this->translator->trans('The following videos were uploaded to Youtube') . ':';
            foreach ($mms as $mm){
                $body = $body."\n -".$mm->getId().": ".$mm->getTitle().' '. $router->generatePath('pumukit_webtv_multimediaobject_index', array('id' => $mm->getId()));
            }
            $error = false;
        } elseif ($cause == "Error") {
            $body = $this->translator->trans('The upload of the following videos has failed') . ':';
            foreach ($mms as $key => $mm){
                $body = $body."\n -".$mm->getId().": ".$mm->getTitle();
                $body = $body. "\n ". $this->translator->trans('With this error'). ":\n".$errors[$key]."\n";
            }
            $error = true;
        }

        $template = 'PumukitNotificationBundle:Email:notification.html.twig';
        $parameters = array('subject' => $subject, 'body' => $body, 'sender_name' => $this->senderService->getSenderName());
        $output = $senderService->sendNotification($emailTo, $subject, $template, $parameters, $error);
        if (0 < $output) {
            $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Sent notification email to "'.$emailTo.'"');
        }
   }
}