<?php

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\NotificationBundle\Services\SenderService;

class YoutubeService
{
    const YOUTUBE_PLAYLIST_URL = 'https://www.youtube.com/playlist?list=';
    const PUB_CHANNEL_YOUTUBE = 'PUCHYOUTUBE';

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
    private $playlistPrivacyStatus;
    private $USE_DEFAULT_PLAYLIST;
    private $DEFAULT_PLAYLIST_COD;
    private $DEFAULT_PLAYLIST_TITLE;
    private $METATAG_PLAYLIST_COD;
    private $PLAYLISTS_MASTER;
    private $DELETE_PLAYLISTS;

    public function __construct(DocumentManager $documentManager, Router $router, TagService $tagService, LoggerInterface $logger, SenderService $senderService, TranslatorInterface $translator, $playlistPrivacyStatus, $useDefaultPlaylist, $defaultPlaylistCod, $defaultPlaylistTitle, $metatagPlaylistCod, $playlistMaster, $deletePlaylists)
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
        $this->pythonDirectory = __DIR__.'/../Resources/data/pyPumukit';
        $this->playlistPrivacyStatus = $playlistPrivacyStatus;
        $this->USE_DEFAULT_PLAYLIST = $useDefaultPlaylist;
        $this->DEFAULT_PLAYLIST_COD = $defaultPlaylistCod;
        $this->DEFAULT_PLAYLIST_TITLE = $defaultPlaylistTitle;
        $this->METATAG_PLAYLIST_COD = $metatagPlaylistCod;
        $this->PLAYLISTS_MASTER = $playlistMaster;
        $this->DELETE_PLAYLISTS = $deletePlaylists;
    }

    /**
     * Upload
     * Given a multimedia object,
     * upload one track to Youtube.
     *
     * @param MultimediaObject $multimediaObject
     * @param int              $category
     * @param string           $privacy
     * @param bool             $force
     *
     * @return int
     */
    public function upload(MultimediaObject $multimediaObject, $category = 27, $privacy = 'private', $force = false)
    {
        $track = null;
        $opencastId = $multimediaObject->getProperty('opencast');
        if ($opencastId !== null) {
            $track = $multimediaObject->getFilteredTrackWithTags(array(), array('sbs'), array('html5'), array(), false);
        } //Or array('sbs','html5') ??
        else {
            $track = $multimediaObject->getTrackWithTag('html5');
        }
        if (null === $track) {
            $track = $multimediaObject->getTrackWithTag('master');
        }
        if (null === $track) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       ."] Error, the Multimedia Object with id '"
                       .$multimediaObject->getId()."' has no master.";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $trackPath = $track->getPath();
        if (!file_exists($trackPath)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       .'] Error, there is no file '.$trackPath;
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
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
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       .'] Error in the upload: '.$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setYoutubeId($out['out']['id']);
        $youtube->setLink('https://www.youtube.com/watch?v='.$out['out']['id']);
        $multimediaObject->setProperty('youtubeurl', $youtube->getLink());
        $this->dm->persist($multimediaObject);
        if ($out['out']['status'] == 'uploaded') {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
        }

        $code = $this->getEmbed($out['out']['id']);
        $youtube->setEmbed($code);
        $youtube->setForce($force);
        $this->dm->persist($youtube);
        $this->dm->flush();
        $youtubeTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            $addedTags = $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       .'] There is no Youtube tag defined with code PUCHYOUTUBE.';
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return 0;
    }

    /**
     * Move to list.
     *
     * @param MultimediaObject $multimediaObject
     * @param string           $playlistTagId
     *
     * @return int
     */
    public function moveToList(MultimediaObject $multimediaObject, $playlistTagId)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        if (null === $playlistTag = $this->tagRepo->find($playlistTagId)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       ."] Error! The tag with id '".$playlistTagId
                       ."' for Youtube Playlist does not exist";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        if (null === $playlistId = $playlistTag->getProperty('youtube')) {
            $errorLog = sprintf('%s [%s] Error! The tag with id %s doesn\'t have a \'youtube\' property!\n Did you use %s first?', __CLASS__, __FUNCTION__, $playlistTag->getId(), 'syncPlaylistsRelations()');
            $this->logger->addError($errorLog);
            throw new \Exception();
        }
        $pyOut = exec('python insertInToList.py --videoid '.$youtube->getYoutubeId().' --playlistid '.$playlistId, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       ."] Error in moving the Multimedia Object '".$multimediaObject->getId()
              ."' to Youtube playlist with id '".$playlistId."': ".$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        if ($out['out'] != null) {
            $youtube->setPlaylist($playlistId, $out['out']);
            if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                $addedTags = $this->tagService->addTagToMultimediaObject($multimediaObject, $playlistTag->getId(), false);
            }
            $this->dm->persist($youtube);
            $this->dm->flush();
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in moving the Multimedia Object '".$multimediaObject->getId()
              ."' to Youtube playlist with id '".$playlistId."'";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return 0;
    }

    /**
     * Delete.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return int
     */
    public function delete(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteVideo.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()
              ."' and mongo id '".$youtube->getId()."': ".$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->setForce(false);
        $this->dm->persist($youtube);
        $this->dm->flush();
        $youtubeEduTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        $youtubeTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            if ($multimediaObject->containsTag($youtubeEduTag)) {
                $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
            }
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] There is no Youtube tag defined with code '".self::PUB_CHANNEL_YOUTUBE."'";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return 0;
    }

    /**
     * Delete orphan.
     *
     * @param Youtube $youtube
     *
     * @return int
     */
    public function deleteOrphan(Youtube $youtube)
    {
        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteVideo.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()
              ."' and mongo id '".$youtube->getId()."': ".$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->setForce(false);
        $this->dm->persist($youtube);
        $this->dm->flush();

        return 0;
    }

    /**
     * Update Metadata.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return int
     */
    public function updateMetadata(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        if (Youtube::STATUS_PUBLISHED === $youtube->getStatus()) {
            $title = $this->getTitleForYoutube($multimediaObject);
            $description = $this->getDescriptionForYoutube($multimediaObject);
            $tags = $this->getTagsForYoutube($multimediaObject);
            $dcurrent = getcwd();
            chdir($this->pythonDirectory);
            $pyOut = exec('python updateVideo.py --videoid '.$youtube->getYoutubeId().' --title "'.addslashes($title).'" --description "'.addslashes($description).'" --tag "'.$tags.'"', $output, $return_var);
            chdir($dcurrent);
            $out = json_decode($pyOut, true);
            if ($out['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error in updating metadata for Youtube video with id '"
                  .$youtube->getId()."': ".$out['error_out'];
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
            $youtube->setSyncMetadataDate(new \DateTime('now'));
            $this->dm->persist($youtube);
            $this->dm->flush();
        }

        return 0;
    }

    /**
     * Update Status.
     *
     * @param Youtube $youtube
     *
     * @return int
     */
    public function updateStatus(Youtube $youtube)
    {
        $multimediaObject = $this->mmobjRepo->find($youtube->getMultimediaObjectId());
        if (null == $multimediaObject) {
            // TODO remove Youtube Document ?????
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error, there is no MultimediaObject referenced from YouTube document with id '"
              .$youtube->getId()."'";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python updateSatus.py --videoid '.$youtube->getYoutubeId(), $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        // NOTE: If the video has been removed, it returns 404 instead of 200 with 'not found Video'
        if ($out['error']) {
            if (strpos($out['error_out'], 'was not found.')) {
                $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
                $this->sendEmail('status removed', $data, array(), array());
                $youtube->setStatus(Youtube::STATUS_REMOVED);
                $this->dm->persist($youtube);
                $youtubeEduTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
                if (null !== $youtubeEduTag) {
                    if ($multimediaObject->containsTag($youtubeEduTag)) {
                        $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
                    }
                } else {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                      ."] There is no Youtube tag defined with code '".self::PUB_CHANNEL_YOUTUBE."'";
                    $this->logger->addWarning($errorLog);
                    /*throw new \Exception($errorLog);*/
                }
                $this->dm->flush();

                return 0;
            } else {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error in verifying the status of the video from youtube with id '"
                  .$youtube->getYoutubeId()."' and mongo id '".$youtube->getId()
                  ."':  ".$out['error_out'];
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
        }
        if (($out['out'] == 'processed') && ($youtube->getStatus() == Youtube::STATUS_PROCESSING)) {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
            $this->sendEmail('finished publication', $data, array(), array());
        } elseif ($out['out'] == 'uploaded') {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
            $this->dm->persist($youtube);
            $this->dm->flush();
        } elseif (($out['out'] == 'rejected') && ($out['rejectedReason'] == 'duplicate') && ($youtube->getStatus() != Youtube::STATUS_DUPLICATED)) {
            $youtube->setStatus(Youtube::STATUS_DUPLICATED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
            $this->sendEmail('duplicated', $data, array(), array());
        }

        return 0;
    }

    /**
     * Update playlists.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return int
     */
    public function updatePlaylists(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        if (!isset($youtube)
            || $youtube->getStatus() !== Youtube::STATUS_PUBLISHED) {
            return 0;
        }
        $this->checkAndAddDefaultPlaylistTag($multimediaObject);
        $has_playlist = false;
        foreach ($multimediaObject->getTags() as $embedTag) {
            if (!$embedTag->isDescendantOfByCod($this->METATAG_PLAYLIST_COD)) {
                //This is not the tag you are looking for
                continue;
            }
            $has_playlist = true;
            $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());
            $playlistId = $playlistTag->getProperty('youtube');

            if (!isset($playlistId)
                || !array_key_exists($playlistId, $youtube->getPlaylists())) {
                //If the tag doesn't exist on youtube playlists
                $this->moveToList($multimediaObject, $playlistTag->getId());
            }
        }
        foreach ($youtube->getPlaylists() as $playlistId => $playlistRel) {
            $playlistTag = $this->getTagByYoutubeProperty($playlistId);
            //If the tag doesn't exist in PuMuKIT
            if ($playlistTag === null) {
                $errorLog = sprintf('%s [%s] Error! The tag with id %s for Youtube Playlist does not exist', __CLASS__, __FUNCTION__, $playlistTagId);
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
            if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                //If the mmobj doesn't have this tag
                $playlistItem = $youtube->getPlaylist($playlistId);
                if ($playlistItem === null) {
                    $errorLog = sprintf('%s [%s] Error! The Youtube document with id %s does not have a playlist item for Playlist %s', __CLASS__, __FUNCTION__, $youtube->getId(), $playlistId);
                    $this->logger->addError($errorLog);
                    throw new \Exception($errorLog);
                }
                $this->deleteFromList($playlistItem, $youtube, $playlistId, false);
            }
        }

        $this->dm->persist($youtube);
        $this->dm->flush();

        return 0;
    }

    /**
     * Updates the relationship between Tags and Youtube Playlists according to the $this->PLAYLISTS_MASTER configuration.
     * If the master is PuMuKIT, it deletes/creates/updates_metadata of all playlists in Youtube based on existent tags.
     * If the master is Youtube, it deletes/creates/updates_metadata of all tags in PuMuKIT based on existent Youtube playlists.
     */
    public function syncPlaylistsRelations()
    {
        $playlistMetaTag = $this->getPlaylistMetaTag();
        $allPlaylistTags = $playlistMetaTag->getChildren();
        $allYoutubePlaylists = $this->getAllYoutubePlaylists();//Returns array with all neccessary, list(['id','title'])
        //REFACTOR THIS ARRAY_MAP >>
        $allYoutubePlaylistsIds = array_map(function ($n) { return $n['id'];}, $allYoutubePlaylists);
        $master = $this->PLAYLISTS_MASTER;
        $allTagsYtId = array();

        foreach ($allPlaylistTags as $tag) {
            $ytPlaylistId = $tag->getProperty('youtube');
            $allTagsYtId[] = $ytPlaylistId;
            if ($ytPlaylistId === null
                        || !in_array($ytPlaylistId, $allYoutubePlaylistsIds)) { //If a playlist on PuMuKIT doesn't exist on Youtube, create it.
                if ($master == 'pumukit') {
                    $this->createYoutubePlaylist($tag);
                } elseif ($this->DELETE_PLAYLISTS) {
                    $this->deletePumukitPlaylist($tag);
                }
            } else { //If a playlist on PuMuKIT exists on Youtube, update metadata (title).
                if ($master == 'pumukit') {
                    $this->updateYoutubePlaylist($tag);
                } else {
                    $this->updatePumukitPlaylist($tag);
                }
            }
        }
        foreach ($allYoutubePlaylists as $ytPlaylist) {
            if (!in_array($ytPlaylist['id'], $allTagsYtId)) { //If a playlist on Youtube doesn't exist on PuMuKIT, delete it.
                if ($master == 'youtube') {
                    $this->createPumukitPlaylist($ytPlaylist);
                } elseif ($this->DELETE_PLAYLISTS) {
                    $this->deleteYoutubePlaylist($ytPlaylist);
                }
            }
        }

        return 0;
    }

    /**
     * Creates a new playlist in Youtube using the 'tag' metadata.
     *
     * @param Tag $tag
     */
    private function createYoutubePlaylist(Tag $tag)
    {
        echo "\ncreate On Youtube: ".$tag->getTitle();
        $command = sprintf('python createPlaylist.py --title "%s" --privacyStatus "%s"', $tag->getTitle(), $this->playlistPrivacyStatus);

        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec($command, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = sprintf('%s [%s] Error in creating in Youtube the playlist from tag with id %s: %s', __CLASS__, __FUNCTION__, $tag->getId(), $out['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        } elseif ($out['out'] != null) {
            $infoLog = sprintf('%s [%s] Created Youtube Playlist %s for Tag with id %s', __CLASS__, __FUNCTION__, $out['out'], $tag->getId());
            $this->logger->addInfo($infoLog);
            $playlistId = $out['out'];
            $tag->setProperty('youtube', $playlistId);
            $tag->setProperty('customfield', 'youtube:text');
            $this->dm->persist($tag);
            $this->dm->flush();
        } else {
            $errorLog = sprintf('%s [%s] Error! Creating the playlist from tag with id %s', __CLASS__, __FUNCTION__, $tag->getId());
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
    }

    /**
     * Creates a new playlist in PuMuKIT using the 'youtubePlaylist' data. Returns the tag created if successful.
     *
     * @param array $youtubePlaylist
     *                               string $youtubePlaylist['id'] = id of the playlist on youtube.
     *                               string $youtubePlaylist['title'] = title of the playlist on youtube.
     *
     * @return Tag
     */
    private function createPumukitPlaylist($youtubePlaylist)
    {
        echo "\ncreate On Pumukit: ".$youtubePlaylist['title'];
        $metatag = $this->getPlaylistMetaTag();
        $tag = new Tag($youtubePlaylist['title']);
        $tag->setCod($youtubePlaylist['id']);
        $tag->setTitle($youtubePlaylist['title']);
        $tag->setDescription('Tag playlist generated automatically from youtube. Do not edit.');
        $tag->setProperty('youtube', $youtubePlaylist['id']);
        $tag->setProperty('customfield', 'youtube:text');
        $tag->setParent($metatag);
        $this->dm->persist($tag);
        $this->dm->flush();

        return $tag;
    }

    /**
     * Deletes an existing playlist on Youtube given a playlist object.
     *
     * @param array $youtubePlaylist
     *                               string $youtubePlaylist['id'] = id of the playlist on youtube.
     *                               string $youtubePlaylist['title'] = title of the playlist on youtube. 
     */
    private function deleteYoutubePlaylist($youtubePlaylist)
    {
        echo "\ndelete On Youtube: ".$youtubePlaylist['title'];
        $command = sprintf('python deletePlaylist.py --playlistid "%s" ', $youtubePlaylist['id']);
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec($command, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if (!isset($out['out'])
            && $out['error_out']['code'] != '404') {
            $errorLog = sprintf('%s [%s] Error in deleting in Youtube the playlist with id %s: %s', __CLASS__, __FUNCTION__, $youtubePlaylist['id'], $out['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $infoLog = sprintf('%s [%s] Deleted Youtube Playlist with id %s', __CLASS__, __FUNCTION__,  $youtubePlaylist['id']);
        $this->logger->addInfo($infoLog);
    }

    /**
     * Deletes an existing playlist on PuMuKIT. Takes care of deleting all relations left by this tag.
     *
     * @param Tag $tag
     */
    private function deletePumukitPlaylist(Tag $tag)
    {
        echo "\ndelete On Pumukit: ".$tag->getTitle();
        $multimediaObjects = $this->mmobjRepo->findWithTag($tag);
        foreach ($multimediaObjects as $mmobj) {
            $this->tagService->removeTagFromMultimediaObject($mmobj, $tag->getId());
            $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($mmobj->getId());
            if (isset($youtube)) {
                $playlist = $youtube->getPlaylist($tag->getProperty('youtube'));
                if (isset($playlist)) {
                    $youtube->removePlaylist($playlist->getId());
                }
            }
        }
        $this->dm->remove($tag);
        $this->dm->flush();
    }

    //TODO Update Scripts:
    private function updateYoutubePlaylist(Tag $tag)
    {
        echo "\nupdate from Pumukit: ".$tag->getTitle();
    }
    private function updatePumukitPlaylist(Tag $tag, $youtubePlaylist = null)
    {
        echo "\nupdate from Youtube: ".$tag->getTitle();
    }

    /**
     * Gets an array of 'playlists' with all youtube playlists data.
     * 
     * returns array
     */
    private function getAllYoutubePlaylists()
    {
        $res = array();
        $playlist = array();
        $command = 'python getAllPlaylists.py';

        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec($command, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = sprintf('%s [%s] Error in executing getAllPlaylists.py:', __CLASS__, __FUNCTION__, $out['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        foreach ($out['out'] as $response) {
            $playlist = array();
            $playlist['id'] = $response['id'];
            $playlist['title'] = $response['snippet']['title'];
            $res[ $playlist['id'] ] = $playlist;
        }

        return $res;
    }

    /**
     * Add the MultimediaObject to the default playlist tag if criteria are met
     * Current Criteria: - USE_DEFAULT_PLAYLIST == true 
     *                   - Multimedia Object doesn't have any playlists tag.   
     *  
     * @param MultimediaObject $multimediaObject
     */
    private function checkAndAddDefaultPlaylistTag(MultimediaObject $multimediaObject)
    {
        if (!$this->USE_DEFAULT_PLAYLIST) {
            return 0;
        }
        $has_playlist = false;
        //This logic is duplicated here from getPlaylistsToUpdate in order to make this function more generic, and the criteria easier to change
        foreach ($multimediaObject->getTags() as $embedTag) {
            if ($embedTag->isDescendantOfByCod($this->METATAG_PLAYLIST_COD)) {
                $has_playlist = true;
                break;
            }
        }
        if ($has_playlist) {
            return 0;
        }
        $playlistTag = $this->getOrCreateDefaultTag();
        //Adds the tag using the service.
        try {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $playlistTag->getId());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Returns the default tag. If it doesn't exist, it creates it first.
     *
     * @return Tag
     */
    private function getOrCreateDefaultTag()
    {
        $playlistTag = $this->tagRepo->findOneByCod($this->DEFAULT_PLAYLIST_COD);
        if (isset($playlistTag)) {
            return $playlistTag;
        }
        $metatagPlaylist = $this->getPlaylistMetaTag();
        $playlistTag = new Tag();
        $playlistTag->setParent($metatagPlaylist);
        $playlistTag->setCod($this->DEFAULT_PLAYLIST_COD);
        $playlistTag->setTitle($this->DEFAULT_PLAYLIST_TITLE);
        $this->dm->persist($playlistTag);
        $this->dm->flush();

        return $playlistTag;
    }
    /**
     * Returns the metaTag for youtube playlists.
     *
     * @return Tag
     */
    private function getPlaylistMetaTag()
    {
        static $metatag = null;
        if (!is_null($metatag)) {
            return $metatag;
        }

        $metatag = $this->tagRepo->findOneByCod($this->METATAG_PLAYLIST_COD);
        if (!isset($metatag)) {
            $errorLog = sprintf('%s [%s] Error! The METATAG_PLAYLIST with cod:%s for YOUTUBE doesn\'t exist! \n Did you load the tag and set the correct cod in parameters.yml?', __CLASS__, __FUNCTION__, $this->METATAG_PLAYLIST_COD);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return $metatag;
    }
    /**
     * Returns a Tag whose youtube property 'youtube' has a $playlistId value.
     *
     * @param string $playlistId
     *
     * return Tag
     */
    private function getTagByYoutubeProperty($playlistId)
    {
        //return $this->tagRepo->getTagByProperty('youtube', $playlistId); //I like this option more (yet unimplemented)
        return $this->tagRepo->createQueryBuilder()
                    ->field('properties.youtube')->equals($playlistId)
                    ->getQuery()->getSingleResult();
    }

    /**
     * Send email.
     *
     * @param string $cause
     * @param array  $succeed
     * @param array  $failed
     * @param array  $errors
     *
     * @return int|bool
     */
    public function sendEmail($cause = '', $succeed = array(), $failed = array(), $errors = array())
    {
        if ($this->senderService->isEnabled()) {
            $subject = $this->buildEmailSubject($cause);
            $body = $this->buildEmailBody($cause, $succeed, $failed, $errors);
            if ($body) {
                $error = $this->getError($errors);
                $emailTo = $this->senderService->getSenderEmail();
                $template = 'PumukitNotificationBundle:Email:notification.html.twig';
                $parameters = array('subject' => $subject, 'body' => $body, 'sender_name' => $this->senderService->getSenderName());
                $output = $this->senderService->sendNotification($emailTo, $subject, $template, $parameters, $error);
                if (0 < $output) {
                    $infoLog = __CLASS__.' ['.__FUNCTION__
                      .'] Sent notification email to "'.$emailTo.'"';
                    $this->logger->addInfo($infoLog);
                } else {
                    $infoLog = __CLASS__.' ['.__FUNCTION__
                      .'] Unable to send notification email to "'
                      .$emailTo.'", '.$output.'email(s) were sent.';
                    $this->logger->addInfo($infoLog);
                }

                return $output;
            }
        }

        return false;
    }

    private function buildEmailSubject($cause = '')
    {
        $subject = ucfirst($cause).' of YouTube video(s)';

        return $subject;
    }

    private function buildEmailBody($cause = '', $succeed = array(), $failed = array(), $errors = array())
    {
        $statusUpdate = array('finished publication', 'status removed', 'duplicated');
        $body = '';
        if (!empty($succeed)) {
            if (in_array($cause, $statusUpdate)) {
                $body = $this->buildStatusUpdateBody($cause, $succeed);
            } else {
                $body = $body.'<br/>The following videos were '.$cause.(substr($cause, -1) === 'e') ? '' : 'e'.'d to Youtube:<br/>';
                foreach ($succeed as $mm) {
                    if ($mm instanceof MultimediaObject) {
                        $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle().' '.$this->router->generate('pumukit_webtv_multimediaobject_index', array('id' => $mm->getId()), true);
                    } elseif ($mm instanceof Youtube) {
                        $body = $body.'<br/> -'.$mm->getId().': '.$mm->getLink();
                    }
                }
            }
        }
        if (!empty($failed)) {
            $body = $body.'<br/>The '.$cause.' of the following videos has failed:<br/>';
            foreach ($failed as $key => $mm) {
                if ($mm instanceof MultimediaObject) {
                    $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle().'<br/>';
                } elseif ($mm instanceof Youtube) {
                    $body = $body.'<br/> -'.$mm->getId().': '.$mm->getLink();
                }
                if (array_key_exists($key, $errors)) {
                    $body = $body.'<br/> With this error:<br/>'.$errors[$key].'<br/>';
                }
            }
        }

        return $body;
    }

    private function buildStatusUpdateBody($cause = '', $succeed = array())
    {
        $body = '';
        if ((array_key_exists('multimediaObject', $succeed)) && (array_key_exists('youtube', $succeed))) {
            $multimediaObject = $succeed['multimediaObject'];
            $youtube = $succeed['youtube'];
            if ($cause === 'finished publication') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The video "'.$multimediaObject->getTitle().'" has been successfully published into YouTube.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ($cause === 'status removed') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The following video has been removed from YouTube: "'.$multimediaObject->getTitle().'"<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ($cause === 'duplicated') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>YouTube has rejected the upload of the video: "'.$multimediaObject->getTitle().'"</br>';
                    $body = $body.'because it has been published previously.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            }
        }

        return $body;
    }

    private function getError($errors = array())
    {
        if (!empty($errors)) {
            return true;
        }

        return false;
    }

    /**
     * Get title for youtube.
     */
    private function getTitleForYoutube(MultimediaObject $multimediaObject)
    {
        $title = $multimediaObject->getTitle();

        if (strlen($title) > 60) {
            while (strlen($title) > 55) {
                $pos = strrpos($title, ' ', 61);
                if ($pos !== false) {
                    $title = substr($title, 0, $pos);
                } else {
                    break;
                }
            }
        }
        while (strlen($title) > 55) {
            $title = substr($title, 0, strrpos($title, ' '));
        }
        if (strlen($multimediaObject->getTitle()) > 55) {
            $title = $title.'(...)';
        }

        return $title;
    }

    /**
     * Get description for youtube.
     */
    private function getDescriptionForYoutube(MultimediaObject $multimediaObject)
    {
        $appInfoLink = $this->router->generate('pumukit_webtv_multimediaobject_index', array('id' => $multimediaObject->getId()), true);
        $series = $multimediaObject->getSeries();
        $break = array('<br />', '<br/>');
        $description = strip_tags($series->getTitle().' - '.$multimediaObject->getTitle()."\n".$multimediaObject->getSubtitle()."\n".str_replace($break, "\n", $multimediaObject->getDescription()).'<br /> Video available at: '.$appInfoLink);

        return $description;
    }

    /**
     * Get tags for youtube.
     */
    private function getTagsForYoutube(MultimediaObject $multimediaObject)
    {
        $numbers = array('1', '2', '3', '4', '5', '6', '7', '8', '9', '0');
        // TODO CMAR
        //$tags = str_replace($numbers, '', $multimediaObject->getKeyword()) . ', CMAR, Mar, Galicia, Portugal, Eurorregión, Campus, Excelencia, Internacional';
        $tags = str_replace($numbers, '', $multimediaObject->getKeyword());

        return $tags;
    }

    /**
     * GetYoutubeDocument
     * returns youtube document associated with the multimediaObject. 
     * If it doesn't exists, it tries to recreate it and logs an error on the output.
     * If it can't, throws an exception with the error.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return Youtube
     */
    private function getYoutubeDocument(MultimediaObject $multimediaObject)
    {
        $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId());
        if ($youtube === null) {
            $youtube = $this->fixRemovedYoutubeDocument($multimediaObject);
            $trace = debug_backtrace();
            $caller = $trace[1];
            $errorLog = 'Error, there was no YouTube data of the Multimedia Object '
                      .$multimediaObject->getId().' Created new Youtube document with id "'
                      .$youtube->getId().'"';
            $errorLog = __CLASS__.' ['.__FUNCTION__."] <-Called by: {$caller['function']}".$errorLog;
            $this->logger->addWarning($errorLog);
        }

        return $youtube;
    }

    /**
     * FixRemovedYoutubeDocument
     * returns a Youtube Document generated based on 'youtubeurl' property from multimediaObject
     * if it can't, throws an exception.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return Youtube
     */
    private function fixRemovedYoutubeDocument(MultimediaObject $multimediaObject)
    {
        //Tries to find the 'youtubeurl' property to recreate the Youtube Document
        $youtubeUrl = $multimediaObject->getProperty('youtubeurl');
        if ($youtubeUrl === null) {
            $errorLog = "PROPERTY 'youtubeurl' for the MultimediaObject id=".$multimediaObject->getId().' DOES NOT EXIST. ¿Is this multimediaObject supposed to be on Youtube?';
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] '.$errorLog;
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        //Tries to get the youtubeId from the youtubeUrl
        $arr = array();
        parse_str(parse_url($youtubeUrl, PHP_URL_QUERY), $arr);
        $youtubeId = isset($arr['v']) ? $arr['v'] : null;

        if ($youtubeId === null) {
            $errorLog = "URL=$youtubeUrl not valid on the MultimediaObject id=".$multimediaObject->getId().' ¿Is this multimediaObject supposed to be on Youtube?';
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] '.$errorLog;
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        //Recreating Youtube Document for the mmobj
        $youtube = new Youtube();
        $youtube->setMultimediaObjectId($multimediaObject->getId());
        $youtube->setLink($youtubeUrl);
        $youtube->setEmbed($this->getEmbed($youtubeId));
        $youtube->setYoutubeId($youtubeId);
        $file_headers = @get_headers($multimediaObject->getProperty('youtubeurl'));
        if ($file_headers[0] === 'HTTP/1.0 200 OK') {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
        } else {
            $youtube->setStatus(Youtube::STATUS_REMOVED);
        }
        $this->dm->persist($youtube);
        $this->dm->flush();
        $multimediaObject->setProperty('youtube', $youtube->getId());
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        return $youtube;
    }

    private function deleteFromList($playlistItem, $youtube, $playlistId, $doFlush = true)
    {
        $dcurrent = getcwd();
        chdir($this->pythonDirectory);
        $pyOut = exec('python deleteFromList.py --id '.$playlistItem, $output, $return_var);
        chdir($dcurrent);
        $out = json_decode($pyOut, true);
        if ($out['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the Youtube video with id '".$youtube->getId()
              ."' from playlist with id '".$playlistItem."': ".$out['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->removePlaylist($playlistId);
        $this->dm->persist($youtube);
        if ($doFlush) {
            $this->dm->flush();
        }
        $infoLog = __CLASS__.' ['.__FUNCTION__
          ."] Removed playlist with youtube id '".$playlistId
          ."' and relation of playlist item id '".$playlistItem
          ."' from Youtube document with Mongo id '".$youtube->getId()."'";
        $this->logger->addInfo($infoLog);
    }

    /**
     * GetEmbed
     * Returns the html embed (iframe) code for a given youtubeId.
     *
     * @param string youtubeId
     *
     * @return string
     */
    private function getEmbed($youtubeId)
    {
        return '<iframe width="853" height="480" src="http://www.youtube.com/embed/'
          .$youtubeId.'" frameborder="0" allowfullscreen></iframe>';
    }
}
