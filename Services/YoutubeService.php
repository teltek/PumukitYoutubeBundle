<?php

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\NotificationBundle\Services\SenderService;

class YoutubeService
{
    private $dm;
    private $router;
    private $tagService;
    private $logger;
    private $senderService;
    private $translator;
    private $youtubeRepo;
    private $tagRepo;
    private $mmobjRepo;
    private $pythonDirectory;

    public function __construct(DocumentManager $documentManager, Router $router, TagService $tagService, LoggerInterface $logger, SenderService $senderService, TranslatorInterface $translator)
    {
        $this->dm = $documentManager;
        $this->router = $router;
        $this->tagService = $tagService;
        $this->logger = $logger;
        $this->senderService = $senderService;
        $this->translator = $translator;
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->pythonDirectory = __DIR__ . "/../Resources/data/pyPumukit";
    }

    /**
     * Upload
     * Given a multimedia object,
     * upload one track to Youtube
     *
     * @param MultimediaObject $multimediaObject
     * @param integer $category
     * @param string $privacy
     * @param boolean $force
     * @return integer 
     */
    public function upload(MultimediaObject $multimediaObject, $category = 27, $privacy = 'private', $force = false)
    {
        $track = null;
        $opencastId = $multimediaObject->getProperty('opencast');
        if ($opencastId !== null) $track = $multimediaObject->getTrackWithTag('sbs');
        else $track = $multimediaObject->getTrackWithTag('html5');
        if (null === $track) $track = $multimediaObject->getTrackWithTag('master');
        if (null === $track) throw new \Exception("Error, the Multimedia Object with id ".$multimediaObject->getId()." has no master");
        $trackPath =  $track->getPath();    
        if (!file_exists($trackPath)) throw new \Exception('Error, there is no file '.$trackPath);
        if (null === $youtubeId = $multimediaObject->getProperty('youtube')) {
            $youtube = new Youtube();
            $youtube->setMultimediaObjectId($multimediaObject->getId());
            $this->dm->persist($youtube);
            $this->dm->flush();
            $multimediaObject->setProperty('youtube', $youtube->getId());
            $this->dm->persist($multimediaObject);
            $this->dm->flush();
        } else {
            $youtube = $this->youtubeRepo->find($youtubeId);
        }

        $title = $this->getTitleForYoutube($multimediaObject);
        $description = $this->getDescriptionForYoutube($multimediaObject);
        $tags = $this->getTagsForYoutube($multimediaObject);
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python upload.py --file '.$trackPath.' --title "'.addslashes($title).'" --description "'.addslashes($description).'" --category '.$category.' --keywords "'.$tags.'" --privacyStatus '.$privacy, $output, $return_var);        
        chdir($dcurrent);
        $out = json_decode($pyOut, true);        
        if ($out['error']) {
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in the upload ".$out['error_out']);
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->dm->persist($youtube);
            $this->dm->flush();
            throw new \Exception("Error in the upload: ".$out['error_out']);
        }
        $youtube->setYoutubeId($out['out']['id']);
        $youtube->setLink("https://www.youtube.com/watch?v=".$out['out']['id']);
        $multimediaObject->setProperty('youtubeurl', $youtube->getLink());
        $this->dm->persist($multimediaObject);
        $code = '<iframe width="853" height="480" src="http://www.youtube.com/embed/'
          . $out['out']['id'].'" frameborder="0" allowfullscreen></iframe>';
        if ($out['out']['status'] == 'uploaded') $youtube->setStatus(Youtube::STATUS_PROCESSING);
        $youtube->setEmbed($code); 
        $youtube->setForce($force);
        $this->dm->persist($youtube);
        $this->dm->flush();
        $youtubeTag = $this->tagRepo->findOneByCod("PUCHYOUTUBE");
        if (null != $youtubeTag) {
            $addedTags = $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        } else {
            throw new \Exception("There is no Youtube tag defined with code PUCHYOUTUBE");
        }

        return 0;    
    }
    
    /**
     * Move to list
     *
     * @param MultimediaObject $multimediaObject
     * @param string $playlistTagId
     * @return integer 
     */
    public function moveToList (MultimediaObject $multimediaObject, $playlistTagId)
    {
        if (null === $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId())) {
            throw new \Exception('Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId());
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        if (null === $playlistTag = $this->tagRepo->find($playlistTagId)){
            throw new \Exception('Error! The tag with id '.$playlistTagId.' for Youtube Playlist does not exist');
        }
        if (null === $playlistId = $playlistTag->getProperty('youtube')){
            $pyOut = exec('python createPlaylist.py --title "'.$playlistTag->getTitle().'"', $output, $return_var);
            $out = json_decode($pyOut, true);
            if ($out['error']) {    
                $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in creating in Youtube the playlist from tag with id ".$playlistTagId." ".$out['error_out']);
                throw new \Exception("Error in creating in Youtube the playlist from tag with id ".$playlistTagId." ".$out['error_out']);
            }elseif ($out['out'] != null) {
                $playlistTag->setProperty('youtube', $out['out']);
                $this->dm->persist($playlistTag);
                $this->dm->flush();
                $playlistId = $out['out'];
            }else {
                throw new \Exception('Error! Creating the playlist from tag with id ' . $playlistTagId);
            }
        }
        $pyOut = exec('python insertInToList.py --videoid '.$youtube->getYoutubeId().' --playlistid '.$playlistId, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in moving the Multimedia Object ".$multimediaObject->getId()." to Youtube playlist with id " .$playlistId.": ".$out['error_out']);
            throw new \Exception("Error in moving the Multimedia Object ".$multimediaObject->getId()." to Youtube playlist with id " .$playlistId.": ".$out['error_out']);
        }
        if ($out['out'] != null) {
            $youtube->setPlaylist($out['out']);
            $this->dm->persist($youtube);
            $this->dm->flush();
        }else{
            throw new \Exception("Error in moving the Multimedia Object ".$multimediaObject->getId()." to Youtube playlist with id " .$playlistId);
        }

        return 0;
    }

    /**
     * Move from list to list
     *
     * @param MultimediaObject $multimediaObject
     * @param string $playlistTagId
     * @return integer 
     */
    public function moveFromListToList (MultimediaObject $multimediaObject, $playlistTagId)
    {
        if (null === $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId())) {
            throw new \Exception('Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId());
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteFromList.py --id '.$youtube->getPlaylist(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);

        return $this->moveToList($multimediaObject, $playlistTagId);
    }

    /**
     * Delete
     *
     * @param MultimediaObject $multimediaObject
     * @return integer 
     */
    public function delete(MultimediaObject $multimediaObject)
    {    
        if (null === $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId())) {
            throw new \Exception('Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId());
        }
        if (null != $youtube->getPlaylist()) {
            $dcurrent = getcwd();
            chdir($this->pythonDirectory);
            $pyOut = exec('python deleteFromList.py --id '.$youtube->getPlaylist(), $output, $return_var);
            chdir($dcurrent);
            $out = json_decode($pyOut, true);
            if ($out['error']){
                $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in deleting the Youtube video with id ".$youtube->getId()." from playlist with id".$youtube->getPlaylist().": ".$out['error_out']);
                throw new \Exception("Error in deleting the Youtube video with id ".$youtube->getId()." from playlist with id".$youtube->getPlaylist().": ".$out['error_out']);
            }
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteVideo.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']){
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in deleting the YouTube video with id ".$youtube->getYoutubeId()." and mongo id ".$youtube->getId().": ".$out['error_out']);
            throw new \Exception("Error in deleting the YouTube video with id ".$youtube->getYoutubeId()." and mongo id ".$youtube->getId().": ".$out['error_out']);
        }
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->setForce(false);
        $this->dm->persist($youtube);
        $this->dm->flush();
        // TODO review (lo desmarca del canal de publicacion)
        $youtubeEduTag = $this->tagRepo->findOneByCod("PUCHYOUTUBE");
        $youtubeTag = $this->tagRepo->findOneByCod("PUCHYOUTUBE");
        if (null != $youtubeTag) {
            if ($multimediaObject->containsTag($youtubeEduTag)) $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
        } else {
            throw new \Exception("There is no Youtube tag defined with code PUCHYOUTUBE");
        }

        return 0; 
    }

    /**
     * Update Metadata
     *
     * @param MultimediaObject $multimediaObject
     * @return integer 
     */
    public function updateMetadata(MultimediaObject $multimediaObject)
    {
        if (null === $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId())) {
            throw new \Exception('Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId());
        }
        if (Youtube::STATUS_PUBLISHED === $youtube->getStatus()) {
            $title = $this->getTitleForYoutube($multimediaObject);
            $description = $this->getDescriptionForYoutube($multimediaObject);
            $tags = $this->getTagsForYoutube($multimediaObject);
            $dcurrent = getcwd();
            chdir($this->pythonDirectory);
            $pyOut = exec('python updateVideo.py --videoid '.$youtube->getYoutubeId().' --title "'.addslashes($title).'" --description "'.addslashes($description).'" --tag "'.$tags.'"', $output, $return_var);
            chdir($dcurrent);
            $out = json_decode($pyOut, true);
            if ($out['error']){
                $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in updating metadata for Youtube video with id ".$youtube->getId().": ".$out['error_out']);
                throw new \Exception("Error in updating metadata for Youtube video with id ".$youtube->getId().": ".$out['error_out']);
            }
        }
        return 0;
    }

    /**
     * Update Status
     *
     * @param Youtube $youtube
     * @return integer
     */
    public function updateStatus(Youtube $youtube)
    {
        $multimediaObject = $this->mmobjRepo->find($youtube->getMultimediaObjectId());
        if (!$multimediaObject) {
            $this->logger->addError('Error, there is no MultimediaObject referenced from YouTube document with id '.$youtube->getId());
            throw new \Exception('Error, there is no MultimediaObject referenced from YouTube document with id '.$youtube->getId());
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python updateSatus.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        // NOTE: If the video has been removed, it returns 404 instead of 200 with 'not found Video'
        if($out['error']){
            if (!strpos($out['error_out'], "was not found.")) {
                // TODO
                //$this->sendMail('Removed', $multimediaObject, $youtube);
                // $this->youtubeService->sendEmail('remove', array(), array($multimediaObject));
                $youtube->setStatus(Youtube::STATUS_REMOVED);
                $this->dm->persist($youtube);
                $youtubeEduTag = $this->tagRepo->findOneByCod("PUCHYOUTUBE");
                if ($youtubeEduTag) {
                    if ($multimediaObject->containsTag($youtubeEduTag)) $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
                } else {
                    throw new \Exception("There is no Youtube tag defined with code PUCHYOUTUBE");
                }
                $this->dm->flush();

                return 0;
            }else{
                $this->logger->addError("Error in verifying the status of the video from youtube with id ".$youtube->getYoutubeId()." and mongo id ".$youtube->getId().":  ".$out['error_out']);
                throw new \Exception("Error in verifying the status of the video from youtube with id ".$youtube->getYoutubeId()." and mongo id ".$youtube->getId().":  ".$out['error_out']);
            }
        }
        if (($out['out'] == "processed") && ($youtube->getStatus() == Youtube::STATUS_PROCESSING)){
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            // TODO
            //$this->sendMail('upload', $multimediaObject);
            // $this->youtubeService->sendEmail('upload', array($multimediaObject), array());
        }elseif ($out['out'] == "uploaded"){
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
            $this->dm->persist($youtube);
            $this->dm->flush();
        }elseif (($out['out'] == 'rejected') && ($out['rejectedReason'] == 'duplicate') && ($youtube->getStatus() != Youtube::STATUS_DUPLICATED)){
            $youtube->setStatus(Youtube::STATUS_DUPLICATED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            // TODO
            //$this->sendMail('duplicate', $multimediaObject);
            // $this->youtubeService->sendEmail('duplicate', array(), array($multimediaObject));
        }
        return 0;
    }

    /**
     * Update playlist
     *
     * @param MultimediaObject $multimediaObject
     * @paran string $playlistTagId
     * @return integer
     */
    public function updatePlaylist(MultimediaObject $multimediaObject, $playlistTagId)
    {
        if (null === $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId())) {
            throw new \Exception('Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId());
        }
        if (null === $playlistTag = $this->tagRepo->find($playlistTagId)) {
            throw new \Exception('Error! The tag with id '.$playlistTagId.' for Youtube Playlist does not exist');
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python getPlaylist.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in getting playlist of video with youtube id ".$youtube->getYoutubeId().": ".$out['error_out']);
            throw new \Exception("Error in getting playlist of video with youtube id ".$youtube->getYoutubeId().": ".$out['error_out']);
        } else {
            $youtubePlaylistId = $playlistTag->getProperty('playlist');
            if ($out['out'] && (null !== $youtubePlaylistId)) {
                if ($out['out'] !== $youtubePlaylistId) {
                    $this->moveFromListToList($multimediaObject, $playlistTagId);
                    $this->logger->addInfo(__CLASS__." [".__FUNCTION__."] MultimediaObject with id ".$multimediaObject->getId()." moved from playlist ".$out['out']." to playlist ".$youtubePlaylistId);
                }
            } else {
                $this->moveToList($multimediaObject, $playlistTagId);
                $youtube->setUpdatePlaylist(false);
                $this->logger->addInfo(__CLASS__." [".__FUNCTION__."] MultimediaObject with id ".$multimediaObject->getId()." moved to playlist ".$youtubePlaylistId);
            }
        }
        $youtube->setUpdatePlaylist(false);
        $this->dm->persist($youtube);
        $this->dm->flush();

        return 0;
    }

    public function sendEmail($cause='', $succeed=array(), $failed=array(), $errors=array())
    {
        if ($this->senderService->isEnabled()) {
            $subject = $this->buildEmailSubject($cause, $succeed, $failed);
            $body = $this->buildEmailBody($cause, $succeed, $failed, $errors);
            $error = $this->getError($errors);
            $emailTo = $this->senderService->getSenderEmail();
            $template = 'PumukitNotificationBundle:Email:notification.html.twig';
            $parameters = array('subject' => $subject, 'body' => $body, 'sender_name' => $this->senderService->getSenderName());
            $output = $this->senderService->sendNotification($emailTo, $subject, $template, $parameters, $error);
            if (0 < $output) {
                $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Sent notification email to "'.$emailTo.'"');
            } else {
                $this->logger->addInfo(__CLASS__.' ['.__FUNCTION__.'] Unable to send notification email to "'.$emailTo.'", '. $output. 'email(s) were sent.');
            }
        }
        return $output;
    }

    private function buildEmailSubject($cause='', $succeed=array(), $failed=array())
    {
        $subject = ucfirst($cause) . 'of YouTube videos';

        return $subject;
    }

    private function buildEmailBody($cause='', $succeed=array(), $failed=array(), $errors=array())
    {
        $body = '';
        if (!empty($succeed)) {
          $body = $body.'<br/>The following videos were '.$cause. (substr($cause, -1) === 'e')?'':'e' .'d to Youtube:<br/>';
            foreach ($succeed as $mm){
                $body = $body."<br/> -".$mm->getId().": ".$mm->getTitle().' '. $this->router->generate('pumukit_webtv_multimediaobject_index', array('id' => $mm->getId()), true);
            }
        }
        if (!empty($failed)) {
            $body = $body.'<br/>The '.$cause.' of the following videos has failed:<br/>';
            foreach ($failed as $key => $mm){
                $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle();
                $body = $body. '<br/> With this error:<br/>'.$errors[$key].'<br/>';
            }
        }

        return $body;
    }

    private function getError($errors=array())
    {
        if (!empty($errors)) return true;
        return false;
    }

    // TODO When EmailBundle is done
    /**
     * Send mail
     */
    /* public static function sendMail($causa, $multimediaObject, $multimediaObjectYt = null, $errores = null) */
    /* { */
    /*     $mail = new sfMail(); */
    /*     $mail->initialize(); */
    /*     $mail->setMailer('sendmail'); */
    /*     $mail->setCharset('utf-8'); */
    /*     $mail->setSender('tv@campusdomar.es', 'CMARTV'); */
    /*     $mail->setFrom('tv@campusdomar.es', 'CMARTV'); */
    /*     $mail->addReplyTo('tv@campusdomar.es'); */
    /*     //    $mail->addAddresses(array('rubenrua@teltek.es', 'nacho.seijo@teltek.es', 'luispena@teltek.es')); */
    /*     $mail->addAddresses(array('nacho.seijo@teltek.es')); */
    /*     if ($causa == 'subido') { */
    /*         $multimediaObject->setCulture('es');  */
    /*         $mail->setSubject('Publicación Terminada'); */
    /*         $mail->setBody(' */

    /*         El vídeo "'.$multimediaObject->getTitle().'" ha sido publicado corrrectamente en YouTube. */

    /*         '); */
    /*     }elseif ($causa == 'Error_subida'){ */
    /*         $multimediaObject->setCulture('es');  */
    /*         $mail->setSubject("Error en la subida a youtube"); */
    /*         $mail->setBody('  */

    /*         Se ha producido un error en la subida a YouTube del vídeo: "'.$multimediaObject->getTitle().'". */
    /*         En breve se reintentará la subida. */

    /*         '); */
    /*     }elseif ($causa == 'Duplicado'){ */
    /*         $multimediaObject->setCulture('es');  */
    /*         $mail->setSubject("Vídeo duplicado en YouTube"); */
    /*         $mail->setBody('  */

    /*         YouTube ha rechazado la subida del vídeo: "'.$multimediaObject->getTitle().'". */
    /*         Porque ya había sido publicado anteriormente. */

    /*         '); */
    /*     }elseif ($causa == 'Eliminado'){ */
    /*         if ($multimediaObject != null) { */
    /*             $multimediaObject->setCulture('es');  */
    /*             $mail->setBody('  */

    /*             El siguiente vídeo ha sido eliminado en YouTube: "'.$multimediaObject->getTitle().'" http://tv.campusdomar.es/en/video/'.$multimediaObject->getId().'.html */
    /*             '); */
    /*         }else { */
    /*             $mail->setSubject("Se ha eliminado un vídeo en Youtube"); */
    /*             $mail->setBody('  */

    /*             El siguiente vídeo ha sido eliminado en YouTube: "'.$multimediaObjectYt->getYoutubeLink().'". */
    /*             Porque se había eliminado el objeto multimedia en Pumukit */
    /*            '); */
    /*         } */
    /*     }elseif ($causa == "Error_borrado"){ */
    /*         $body = '  */
    /*         Ha fallado el borrado de YouTube de los siguientes vídeos: */

    /*         '; */
    /*         if (is_array($multimediaObject)) { */
    /*             $multimediaObjects = $multimediaObject; */
    /*             foreach ($multimediaObjects as $key => $multimediaObject){ */
    /*                 $body = $body."\n -".$multimediaObject->getId()." ".$multimediaObject->getTitle(); */
    /*                 $body = $body. "\nCon el siguiente error:\n".$errores[$key]."\n"; */
    /*             } */
    /*         } */
    /*         $mail->setBody($body); */
    /*     }elseif ($causa == "Borrado_multiple"){ */
    /*         $body = ' */
    /*         Se han borrado los siguientes vídeos de Youtube: */

    /*         '; */
    /*         if (is_array($multimediaObject)) { */
    /*             $multimediaObjects = $multimediaObject; */
    /*             foreach ($multimediaObjects as $multimediaObject){ */
    /*                 $body = $body."\n -".$multimediaObject->getId()." ".$multimediaObject->getTitle(); */
    /*             } */
    /*         } */
    /*         $mail->setBody($body);     */
    /*     } */
    /*     $mail->send(); */
    /* } */

    /**
     * Get title for youtube
     */

    private function getTitleForYoutube(MultimediaObject $multimediaObject)
    {
        $title = $multimediaObject->getTitle();

        if(strlen($title) > 60){
            while(strlen($title) > 55){
                $pos = strrpos($title, " ", 61);
                if ($pos !== FALSE) 
                    $title = substr($title, 0, $pos);
                else 
                    break;
            }
        }    
        while (strlen($title) > 55) {
            $title = substr($title, 0, strrpos($title, " "));
        }
        if(strlen($multimediaObject->getTitle()) > 55) $title = $title."(...)";

        return $title;
    }

    /**
     * Get description for youtube
     */
    private function getDescriptionForYoutube(MultimediaObject $multimediaObject)
    {
        $appInfoLink = $this->router->generate("pumukit_webtv_multimediaobject_index", array("id" => $multimediaObject->getId()), true);
        $series = $multimediaObject->getSeries();
        $break = array("<br />", "<br/>");
        $description = strip_tags($series->getTitle() . " - " . $multimediaObject->getTitle() . "\n".$multimediaObject->getSubtitle()."\n". str_replace($break, "\n", $multimediaObject->getDescription()).'<br /> Video available at: '.$appInfoLink);

        return $description;
    }

    /**
     * Get tags for youtube
     */
    private function getTagsForYoutube(MultimediaObject $multimediaObject)
    {
        $numbers = array("1", "2", "3", "4", "5", "6", "7", "8", "9", "0");
        // TODO CMAR
        //$tags = str_replace($numbers, '', $multimediaObject->getKeyword()) . ', CMAR, Mar, Galicia, Portugal, Eurorregión, Campus, Excelencia, Internacional';
        $tags = str_replace($numbers, '', $multimediaObject->getKeyword());

        return $tags;
    }
}