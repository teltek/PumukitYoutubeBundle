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
    const YOUTUBE_PLAYLIST_URL = 'https://www.youtube.com/playlist?list=';

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
        if ($opencastId !== null) $track = $multimediaObject-> getFilteredTrackWithTags(array(), array('sbs'), array('html5'), array(), false);
        else $track = $multimediaObject->getTrackWithTag('html5');
        if (null === $track) $track = $multimediaObject->getTrackWithTag('master');
        if (null === $track) {
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error, the Multimedia Object with id ".$multimediaObject->getId()." has no master");
            $errorLog = "Error, the Multimedia Object with id ".$multimediaObject->getId()." has no master";
            return $errorLog;
        }
        $trackPath =  $track->getPath();    
        if (!file_exists($trackPath)) {
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error, there is no file ".$trackPath);
            $errorLog = 'Error, there is no file '.$trackPath;
            return $errorLog;
        }
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
            $errorLog = "Error in the upload: ".$out['error_out'];
            return $errorLog;
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
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] There is no Youtube tag defined with code PUCHYOUTUBE");
            $errorLog = "There is no Youtube tag defined with code PUCHYOUTUBE";
            return $errorLog;
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
            //TODO Check:
            $errorLog = $this->fixRemovedYoutubeDocument($multimediaObject);
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] " . $errorLog);
            //$errorLog = 'Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId();
            return $errorLog;
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        if (null === $playlistTag = $this->tagRepo->find($playlistTagId)){
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error! The tag with id '".$playlistTagId."' for Youtube Playlist does not exist");
            $errorLog = 'Error! The tag with id '.$playlistTagId.' for Youtube Playlist does not exist';
            return $errorLog;
        }
        $youtubePlaylist = $this->checkYoutubePlaylist($playlistTag->getProperty('youtube'));
        if (null === $playlistId = $playlistTag->getProperty('youtube') || (!$youtubePlaylist)){
            $pyOut = exec('python createPlaylist.py --title "'.$playlistTag->getTitle().'"', $output, $return_var);
            $out = json_decode($pyOut, true);
            if ($out['error']) {    
                $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in creating in Youtube the playlist from tag with id ".$playlistTagId." ".$out['error_out']);
                $errorLog = "Error in creating in Youtube the playlist from tag with id ".$playlistTagId." ".$out['error_out'];
                return $errorLog;
            }elseif ($out['out'] != null) {
                $playlistTag->setProperty('youtube', $out['out']);
                $this->dm->persist($playlistTag);
                $this->dm->flush();
                $playlistId = $out['out'];
            }else {
                $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error! Creating the playlist from tag with id '" . $playlistTagId . "'");
                $errorLog = 'Error! Creating the playlist from tag with id ' . $playlistTagId;
                return $errorLog;
            }
        }
        $pyOut = exec('python insertInToList.py --videoid '.$youtube->getYoutubeId().' --playlistid '.$playlistId, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in moving the Multimedia Object ".$multimediaObject->getId()." to Youtube playlist with id " .$playlistId.": ".$out['error_out']);
            $errorLog = "Error in moving the Multimedia Object ".$multimediaObject->getId()." to Youtube playlist with id " .$playlistId.": ".$out['error_out'];
            return $errorLog;
        }
        if ($out['out'] != null) {
            $youtube->setPlaylist($out['out']);
            $this->dm->persist($youtube);
            $this->dm->flush();
        }else{
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in moving the Multimedia Object ".$multimediaObject->getId()." to Youtube playlist with id " .$playlistId);
            $errorLog = "Error in moving the Multimedia Object ".$multimediaObject->getId()." to Youtube playlist with id " .$playlistId;
            return $errorLog;
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
            //TODO Check:
            $errorLog = $this->fixRemovedYoutubeDocument($multimediaObject);
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] " . $errorLog);
            //$errorLog = 'Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId();
            return $errorLog;
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
            //TODO Check:
            $errorLog = $this->fixRemovedYoutubeDocument($multimediaObject);
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] " . $errorLog);
            //$errorLog = 'Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId();
            return $errorLog;
        }
        $youtubePlaylist = $this->checkYoutubePlaylist($youtube->getPlaylist());
        if ((null != $youtube->getPlaylist()) && $youtubePlaylist) {
            $dcurrent = getcwd();
            chdir($this->pythonDirectory);
            $pyOut = exec('python deleteFromList.py --id '.$youtube->getPlaylist(), $output, $return_var);
            chdir($dcurrent);
            $out = json_decode($pyOut, true);
            if ($out['error']){
                $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in deleting the Youtube video with id ".$youtube->getId()." from playlist with id".$youtube->getPlaylist().": ".$out['error_out']);
                $errorLog = "Error in deleting the Youtube video with id ".$youtube->getId()." from playlist with id".$youtube->getPlaylist().": ".$out['error_out'];
                return $errorLog;
            }
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteVideo.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']){
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in deleting the YouTube video with id ".$youtube->getYoutubeId()." and mongo id ".$youtube->getId().": ".$out['error_out']);
            $errorLog = "Error in deleting the YouTube video with id ".$youtube->getYoutubeId()." and mongo id ".$youtube->getId().": ".$out['error_out'];
            return $errorLog;
        }
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->setForce(false);
        $this->dm->persist($youtube);
        $this->dm->flush();
        $youtubeEduTag = $this->tagRepo->findOneByCod("PUCHYOUTUBE");
        $youtubeTag = $this->tagRepo->findOneByCod("PUCHYOUTUBE");
        if (null != $youtubeTag) {
            if ($multimediaObject->containsTag($youtubeEduTag)) $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
        } else {
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] There is no Youtube tag defined with code PUCHYOUTUBE");
            $errorLog = "There is no Youtube tag defined with code PUCHYOUTUBE";
            return $errorLog;
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
            //TODO Check:
            $errorLog = $this->fixRemovedYoutubeDocument($multimediaObject);
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] " . $errorLog);
            //$errorLog = 'Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId();
            return $errorLog;
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
                $errorLog = "Error in updating metadata for Youtube video with id ".$youtube->getId().": ".$out['error_out'];
                return $errorLog;
            }
            $youtube->setSyncMetadataDate(new \DateTime('now'));
            $this->dm->persist($youtube);
            $this->dm->flush();
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
        if (null == $multimediaObject) {
            // TODO remove Youtube Document ?????
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error, there is no MultimediaObject referenced from YouTube document with id '".$youtube->getId()."'");
            $errorLog = 'Error, there is no MultimediaObject referenced from YouTube document with id '.$youtube->getId();
            return $errorLog;
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python updateSatus.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        // NOTE: If the video has been removed, it returns 404 instead of 200 with 'not found Video'
        if($out['error']){
            if (!strpos($out['error_out'], "was not found.")) {
                $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
                $this->sendEmail('status removed', $data, array(), array());
                $youtube->setStatus(Youtube::STATUS_REMOVED);
                $this->dm->persist($youtube);
                $youtubeEduTag = $this->tagRepo->findOneByCod("PUCHYOUTUBE");
                if (null !== $youtubeEduTag) {
                    if ($multimediaObject->containsTag($youtubeEduTag)) $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
                } else {
                    $this->logger->addError(__CLASS__." [".__FUNCTION__."] There is no Youtube tag defined with code PUCHYOUTUBE");
                    $errorLog = "There is no Youtube tag defined with code PUCHYOUTUBE";
                    return $errorLog;
                }
                $this->dm->flush();

                return 0;
            }else{
                $this->logger->addError("Error in verifying the status of the video from youtube with id ".$youtube->getYoutubeId()." and mongo id ".$youtube->getId().":  ".$out['error_out']);
                $errorLog = "Error in verifying the status of the video from youtube with id ".$youtube->getYoutubeId()." and mongo id ".$youtube->getId().":  ".$out['error_out'];
                return $errorLog;
            }
        }
        if (($out['out'] == "processed") && ($youtube->getStatus() == Youtube::STATUS_PROCESSING)){
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
            $this->sendEmail('finished publication', $data, array(), array());
        }elseif ($out['out'] == "uploaded"){
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
            $this->dm->persist($youtube);
            $this->dm->flush();
        }elseif (($out['out'] == 'rejected') && ($out['rejectedReason'] == 'duplicate') && ($youtube->getStatus() != Youtube::STATUS_DUPLICATED)){
            $youtube->setStatus(Youtube::STATUS_DUPLICATED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
            $this->sendEmail('duplicated', $data, array(), array());
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
            //TODO Check:
            $errorLog = $this->fixRemovedYoutubeDocument($multimediaObject);
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] " . $errorLog);
            //$errorLog = 'Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId();
            return $errorLog;
        }
        if (null === $playlistTag = $this->tagRepo->find($playlistTagId)) {
            $this->logger->addError(__CLASS__.". [".__FUNCTION__."] Error! The tag with id '".$playlistTagId."' for Youtube Playlist does not exist");
            $errorLog = 'Error! The tag with id '.$playlistTagId.' for Youtube Playlist does not exist';
            return $errorLog;
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python getPlaylist.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $this->logger->addError(__CLASS__." [".__FUNCTION__."] Error in getting playlist of video with youtube id ".$youtube->getYoutubeId().": ".$out['error_out']);
            $errorLog = "Error in getting playlist of video with youtube id ".$youtube->getYoutubeId().": ".$out['error_out'];
            return $errorLog;
        } else {
            $youtubePlaylistId = $playlistTag->getProperty('youtube');
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
            $subject = $this->buildEmailSubject($cause);
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
            return $output;
        }
        return false;
    }

    private function buildEmailSubject($cause='')
    {
        $subject = ucfirst($cause) . ' of YouTube video(s)';

        return $subject;
    }

    private function buildEmailBody($cause='', $succeed=array(), $failed=array(), $errors=array())
    {
        $statusUpdate = array('finished publication', 'status removed', 'duplicated');
        $body = '';
        if (!empty($succeed)) {
            if (in_array($cause, $statusUpdate)) {
                $body = $this->buildStatusUpdateBody($cause, $succeed);
            } else {
                $body = $body.'<br/>The following videos were '.$cause. (substr($cause, -1) === 'e')?'':'e' .'d to Youtube:<br/>';
                foreach ($succeed as $mm){
                    $body = $body."<br/> -".$mm->getId().": ".$mm->getTitle().' '. $this->router->generate('pumukit_webtv_multimediaobject_index', array('id' => $mm->getId()), true);
                }
            }
        }
        if (!empty($failed)) {
            $body = $body.'<br/>The '.$cause.' of the following videos has failed:<br/>';
            foreach ($failed as $key => $mm){
                $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle().'<br/>';
                if (array_key_exists($key, $errors)) $body = $body. '<br/> With this error:<br/>'.$errors[$key].'<br/>';
            }
        }

        return $body;
    }

    private function buildStatusUpdateBody($cause='', $succeed=array())
    {
        $body = '';
        if ((array_key_exists('multimediaObject', $succeed)) && (array_key_exists('youtube', $succeed))) {
            $multimediaObject = $succeed['multimediaObject'];
            $youtube = $succeed['youtube'];
            if ($cause === 'finished publication') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body . '<br/>The video "'.$multimediaObject->getTitle() . '" has been successfully published into YouTube.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body . '<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ($cause === 'status removed') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body . '<br/>The following video has been removed from YouTube: "'.$multimediaObject->getTitle() . '"<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body . '<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ($cause === 'duplicated') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body . '<br/>YouTube has rejected the upload of the video: "'.$multimediaObject->getTitle() . '"</br>';
                    $body = $body . 'because it has been published previously.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body . '<br/>'.$youtube->getLink().'<br/>';
                }
            }
        }
        return $body;
    }

    private function getError($errors=array())
    {
        if (!empty($errors)) return true;
        return false;
    }

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

    private function fixRemovedYoutubeDocument(MultimediaObject $multimediaObject)
    {
        $youtube = new Youtube();
        $youtube->setMultimediaObjectId($multimediaObject->getId());
        $youtube->setLink($multimediaObject->getProperty('youtubeurl'));
        $youtube->setEmbed();
        $youtube->setYoutubeId();
        $file_headers = @get_headers($multimediaObject->getProperty('youtubeurl'));
        if ($file_headers[0] === "HTTP/1.0 200 OK") {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
        } else {
            $youtube->setStatus(Youtube::STATUS_REMOVED);
        }
        $this->dm->persist($youtube);
        $this->dm->flush();
        $multimediaObject->setProperty('youtube', $youtube->getId());
        $this->dm->persist($multimediaObject);
        $this->dm->flush();
        $errorLog = 'Error, there is no YouTube data of the Multimedia Object '.$multimediaObject->getId(). ' Created new Youtube document with id "'. $youtube->getId() . '"';

        return $errorLog;
    }

    private function checkYoutubePlaylist($youtubePlaylistId)
    {
        $file_headers = @get_headers(self::YOUTUBE_PLAYLIST_URL . $youtubePlaylistId);
        return ($file_headers[0] === "HTTP/1.0 200 OK");
    }
}