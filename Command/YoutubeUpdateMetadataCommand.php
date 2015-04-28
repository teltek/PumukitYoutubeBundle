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

class YoutubeUpdateMetadataCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;
    private $broadcastRepo = null;

    private $youtubeService;

    private $okUpdates = array();
    private $failedUpdates = array();
    private $errors = array();
    private $correct = false;
    private $failure = false;

    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:update:metadata')
            ->setDescription('Update Youtube metadata from Multimedia Objects')
            ->setHelp(<<<EOT
Command to upload a controlled videos to Youtube.

EOT
          );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initParameters();

        $mms = $this->getMultimediaObjectsInYoutube();
        $this->updateVideosInYoutube($mms, $output);
    }

    private function initParameters()
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository("PumukitSchemaBundle:Tag");
        $this->mmobjRepo = $this->dm->getRepository("PumukitSchemaBundle:MultimediaObject");
        $this->youtubeRepo = $this->dm->getRepository("PumukitYoutubeBundle:Youtube");
        $this->broadcastRepo = $this->dm->getRepository("PumukitSchemaBundle:Broadcast");

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->okUpdates = array();
        $this->failedUpdates = array();
        $this->errors = array();
        $this->correct = false;
        $this->failure = false;

        $this->logger = $this->getContainer()->get('logger');
    }

    private function updateVideosInYoutube($mms, OutputInterface $output)
    {
        foreach ($mms as $mm) {
            try {
                $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Started updating Youtube video of MultimediaObject with id "'.$mm->getId().'"');
                $output->writeln('Started updating Youtube video of MultimediaObject with id "'.$mm->getId().'"');
                $outUpdate = $this->youtubeService->updateMetadata($mm);
                if (0 !== $outUpdate) {
                $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Uknown output on the update in Youtube video of MultimediaObject with id "'.$mm->getId().'"');
                    $output->writeln('Unknown output on the update in Youtube video of MultimediaObject with id "'.$mm->getId().'"');
                }
                $this->okUpdates[] = $mm;
                $this->correct = true;
            } catch (\Exception $e) {
                $this->logger->addError(__CLASS__.' ['.__FUNCTION__.'] The update of the video from the Multimedia Object with id "'.$mm->getId().'" failed: '.$e->getMessage());
                $output->writeln('The update of the video from the Multimedia Object with id "'.$mm->getId().'" failed: '.$e->getMessage());
                $this->failedUpdates[] = $mm;
                $this->errors[] = substr($e->getMessage(), 0, 100);
                $this->failure = true;
            }
        }
        $this->checkResultsAndSendEmail();
    }

    private function getMultimediaObjectsInYoutube()
    {
        $mms = $this->mmobjRepo->createQueryBuilder()
          ->field('properties.youtube')->exists(true)
          ->getQuery()
          ->execute();

        return $mms;
    }

    private function checkResultsAndSendEmail()
    {
        if ($this->correct){
            // TODO when EmailBundle is done
            //$this->sendEmail($this->okRemoved, "Multiple updating");
            $this->correct = false;
            $this->okUpdates = array();
        }
        if ($this->failure){
            // TODO when EmailBundle is done
            //$this->sendEmail($this->failedUpdates, "Error", $this->errors);
            $this->failure = false;
            $this->failedUpdates = array();
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
