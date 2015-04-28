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

    private $okDeleted = array();
    private $failedDeleted = array();
    private $errors = array();
    private $correct = false;
    private $failure = false;

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

        // TODO When tag IMPORTANT is defined as child of PUBLICATION DECISION Tag
        // $notImportantMms = $this->getMultimediaObjectsInYoutubeWithoutTagCode($publishedYoutubeIds, 'IMPORTANT');
        // $this->deleteVideosFromYoutube($notImportantMms, $output);
        
        $notPublicMms = $this->getMultimediaObjectsInYoutubeWithoutBroadcast($publishedYoutubeIds, Broadcast::BROADCAST_TYPE_PUB);
        $this->deleteVideosFromYoutube($notPublicMms, $output);
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository("PumukitSchemaBundle:Tag");
        $this->mmobjRepo = $this->dm->getRepository("PumukitSchemaBundle:MultimediaObject");
        $this->youtubeRepo = $this->dm->getRepository("PumukitYoutubeBundle:Youtube");
        $this->broadcastRepo = $this->dm->getRepository("PumukitSchemaBundle:Broadcast");

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okDeleted = array();
        $this->failedDeleted = array();
        $this->errors = array();
        $this->correct = false;
        $this->failure = false;

        $this->logger = $this->getContainer()->get('logger');
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
                $this->okDeleted[] = $mm;
                $this->correct = true;
            } catch (\Exception $e) {
                $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] Removal of video from MultimediaObject with id "'.$mm->getId().'" has failed. '.$e->getMessage());
                $output->writeln('Removal of video from MultimediaObject with id "'.$mm->getId().'" has failed. '.$e->getMessage());
                $this->failedDeleted[] = $mm;
                $this->errors[] = substr($e->getMessage(), 0, 100);
                $this->failure = true;
            }
        }
    }

    private function getStringIds($mongoIds)
    {
      $stringIds = array();
      foreach ($mongoIds as $mongoId)
        {
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
            ->field('properties.youtube')->in($ytids)
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
        if ($this->correct){
            // TODO when EmailBundle is done
            //$this->sendEmail($this->okDeleted, "Multiple removal");
            $this->correct = false;
            $this->okUploads = array();
        }
        if ($this->failure){
            // TODO when EmailBundle is done
            //$this->sendEmail($this->failedUploads, "Error", $this->errors);
            $this->failure = false;
            $this->failedUploads = array();
        }
    }

    // TODO when EmailBundle is done
    /* function sendEmail($mms, $causa, $errores = null) */
    /* { */
    /*   $mail = new sfMail(); */
    /*   $mail->initialize(); */
    /*   $mail->setMailer('sendmail'); */
    /*   $mail->setCharset('utf-8'); */
    /*   $mail->setSender('tv@campusdomar.es', 'CMARTv (no-reply)'); */
    /*   $mail->setFrom('tv@campusdomar.es', 'CMARTv (no-reply)'); */
    /*   $mail->addReplyTo('tv@campusdomar.es'); */
    /*   $mail->addAddresses(array('rubenrua@teltek.es', 'nacho.seijo@teltek.es', 'luispena@teltek.es')); */
    /*   $mail->setSubject('Resultado de subida de vídeos'); */
    /*   if ($causa == "Ok"){ */
    /*     $body = ' */
    /*    Se han subido los siguientes vídeos a Youtube: */
    /*           '; */
    /*     foreach ($mms as $mm){ */
    /*       $body = $body."\n -".$mm->getId().": ".$mm->getTitle().' http://tv.campusdomar.es/en/video/'.$mm->getId().'.html'; */
    /*     } */
    /*   } */
    /*   elseif ($causa == "Error"){ */
    /*     $body = '  */
    /*           Ha fallado la subida a YouTube de los siguientes vídeos: */
    /*           '; */
    /*        foreach ($mms as $key => $mm){ */
    /* 	 $body = $body."\n -".$mm->getId().": ".$mm->getTitle(); */
    /* 	 $body = $body. "\nCon el siguiente error:\n".$errores[$key]."\n"; */
    /*        } */
    /*   } */
    /*   $mail->setBody($body); */
    /*   $mail->send(); */
    /* } */
}