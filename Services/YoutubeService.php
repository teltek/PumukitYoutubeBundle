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
    private $playlistPrivacyStatus;
    private $ytLocale;
    private $USE_DEFAULT_PLAYLIST;
    private $DEFAULT_PLAYLIST_COD;
    private $DEFAULT_PLAYLIST_TITLE;
    private $METATAG_PLAYLIST_COD;
    private $PLAYLISTS_MASTER;
    private $DELETE_PLAYLISTS;

    public function __construct(DocumentManager $documentManager, Router $router, TagService $tagService, LoggerInterface $logger, SenderService $senderService = null, TranslatorInterface $translator, YoutubeProcessService $youtubeProcessService, $playlistPrivacyStatus, $locale, $useDefaultPlaylist, $defaultPlaylistCod, $defaultPlaylistTitle, $metatagPlaylistCod, $playlistMaster, $deletePlaylists, $pumukitLocales)
    {
        $this->dm = $documentManager;
        $this->router = $router;
        $this->tagService = $tagService;
        $this->logger = $logger;
        $this->senderService = $senderService;
        $this->translator = $translator;
        $this->youtubeProcessService = $youtubeProcessService;
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->playlistPrivacyStatus = $playlistPrivacyStatus;
        $this->ytLocale = $locale;
        $this->USE_DEFAULT_PLAYLIST = $useDefaultPlaylist;
        $this->DEFAULT_PLAYLIST_COD = $defaultPlaylistCod;
        $this->DEFAULT_PLAYLIST_TITLE = $defaultPlaylistTitle;
        $this->METATAG_PLAYLIST_COD = $metatagPlaylistCod;
        $this->PLAYLISTS_MASTER = $playlistMaster;
        $this->DELETE_PLAYLISTS = $deletePlaylists;

        if(!in_array($this->ytLocale, $pumukitLocales)){
            $this->ytLocale = $translator->getLocale();
        }
    }

    /**
     * Upload
     * Given a multimedia object,
     * upload one track to Youtube.
     *
     * @param MultimediaObject $multimediaObject
     * @param int $category
     * @param string $privacy
     * @param bool $force
     * @return int
     * @throws \Exception
     */
    public function upload(MultimediaObject $multimediaObject, $category = 27, $privacy = 'private', $force = false)
    {
        $track = null;
        $opencastId = $multimediaObject->getProperty('opencast');
        if ($opencastId !== null) {
            $track = $multimediaObject->getFilteredTrackWithTags(array(), array('sbs'), array('html5'), array(), false);
        } //Or array('sbs','html5') ??
        else {
            $track = $multimediaObject->getTrackWithTag('html5'); //TODO get Only the video track with tag html5
        }
        if ((null === $track) || ($track->isOnlyAudio())) {
            $track = $multimediaObject->getTrackWithTag('master');
        }
        if ((null === $track) || ($track->isOnlyAudio())) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       ."] Error, the Multimedia Object with id '"
                       .$multimediaObject->getId()."' has no video master.";
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
            $multimediaObject->setProperty('youtube', $youtube->getId());
            $this->dm->persist($multimediaObject);
        } else {
            $youtube = $this->youtubeRepo->find($youtubeId);
        }

        $title = $this->getTitleForYoutube($multimediaObject);
        $description = $this->getDescriptionForYoutube($multimediaObject);
        $tags = $this->getTagsForYoutube($multimediaObject);

        $sResult = $this->youtubeProcessService->upload($trackPath, $title, $description, $category, $tags, $privacy);
        if ($sResult['error']) {
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       .'] Error in the upload: '.$sResult['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setYoutubeId($sResult['out']['id']);
        $youtube->setLink('https://www.youtube.com/watch?v='.$sResult['out']['id']);
        $multimediaObject->setProperty('youtubeurl', $youtube->getLink());
        $this->dm->persist($multimediaObject);
        if ($sResult['out']['status'] == 'uploaded') {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
        }

        $code = $this->getEmbed($sResult['out']['id']);
        $youtube->setEmbed($code);
        $youtube->setForce($force);

        $now = new \DateTime('now');
        $youtube->setSyncMetadataDate($now);
        $youtube->setUploadDate($now);
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
     * @param $playlistTagId
     * @return int
     * @throws \Exception
     */
    public function moveToList(MultimediaObject $multimediaObject, $playlistTagId)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

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

        $sResult = $this->youtubeProcessService->insertInToList($youtube, $playlistId);
        if ($sResult['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       ."] Error in moving the Multimedia Object '".$multimediaObject->getId()
              ."' to Youtube playlist with id '".$playlistId."': ".$sResult['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        if ($sResult['out'] != null) {
            $youtube->setPlaylist($playlistId, $sResult['out']);
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
     * Delete
     *
     * @param MultimediaObject $multimediaObject
     * @return int
     * @throws \Exception
     */
    public function delete(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }
        $sResult = $this->youtubeProcessService->deleteVideo($youtube);
        if ($sResult['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()
              ."' and mongo id '".$youtube->getId()."': ".$sResult['error_out'];
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
     * @return int
     * @throws \Exception
     */
    public function deleteOrphan(Youtube $youtube)
    {
        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }

        $sResult = $this->youtubeProcessService->deleteVideo($youtube);
        if ($sResult['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()
              ."' and mongo id '".$youtube->getId()."': ".$sResult['error_out'];
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
     * @return int
     * @throws \Exception
     */
    public function updateMetadata(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        if (Youtube::STATUS_PUBLISHED === $youtube->getStatus()) {
            $title = $this->getTitleForYoutube($multimediaObject);
            $description = $this->getDescriptionForYoutube($multimediaObject);
            $tags = $this->getTagsForYoutube($multimediaObject);

            $sResult = $this->youtubeProcessService->updateVideo($youtube, $title, $description, $tags);
            if ($sResult['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error in updating metadata for Youtube video with id '"
                  .$youtube->getId()."': ".$sResult['error_out'];
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
     * @return int
     * @throws \Exception
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

        if($youtube->getYoutubeId() === null) {
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       ."] The object Youtube with id: ".$youtube->getId()." does not have a Youtube ID variable set.";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        $sResult = $this->youtubeProcessService->getData('status', $youtube->getYoutubeId());
        // NOTE: If the video has been removed, it returns 404 instead of 200 with 'not found Video'
        if ($sResult['error']) {
            if (strpos($sResult['error_out'], 'was not found.')) {
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
                  ."':  ".$sResult['error_out'];
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
        }
        if (($sResult['out'] == 'processed') && ($youtube->getStatus() == Youtube::STATUS_PROCESSING)) {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
            $this->sendEmail('finished publication', $data, array(), array());
        } elseif ($sResult['out'] == 'uploaded') {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
            $this->dm->persist($youtube);
            $this->dm->flush();
        } elseif (($sResult['out'] == 'rejected') && ($sResult['rejectedReason'] == 'duplicate') && ($youtube->getStatus() != Youtube::STATUS_DUPLICATED)) {
            $youtube->setStatus(Youtube::STATUS_DUPLICATED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array('multimediaObject' => $multimediaObject, 'youtube' => $youtube);
            $this->sendEmail('duplicated', $data, array(), array());
        }

        return 0;
    }

    /**
     * Update Status.
     *
     * @param $yid
     * @return mixed
     * @throws \Exception
     */
    public function getVideoMeta($yid)
    {
        $sResult = $this->youtubeProcessService->getData('status', $yid);
        if ($sResult['error']) {
            $errorLog = __CLASS__ .' [' . __FUNCTION__
                . "] Error getting meta from YouTube id"
                . $yid . ": " . $sResult['error_out'];
            $this->logger->error($errorLog);
            throw new \Exception($errorLog);
        }

        return $sResult;
    }

    /**
     * Update playlists.
     *
     * @param MultimediaObject $multimediaObject
     * @return int
     * @throws \Exception
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
                $errorLog = sprintf('%s [%s] Error! The tag with id %s => %s for Youtube Playlist does not exist', __CLASS__, __FUNCTION__, $playlistId, $playlistRel);
                $this->logger->warning($errorLog);
                continue;
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
     *
     * @return int
     */
    public function syncPlaylistsRelations()
    {
        if($this->USE_DEFAULT_PLAYLIST) {
            $this->getOrCreateDefaultTag();
        }
        $playlistMetaTag = $this->getPlaylistMetaTag();
        //$allPlaylistTags = $playlistMetaTag->getChildren(); //Doctrine ODM bug reapeat last element
        $allPlaylistTags = $this->dm
            ->createQueryBuilder('PumukitSchemaBundle:Tag')
            ->field('parent')->references($playlistMetaTag)
            ->getQuery()
            ->execute();

        $allYoutubePlaylists = $this->getAllYoutubePlaylists();//Returns array with all neccessary, list(['id','title'])
        //REFACTOR THIS ARRAY_MAP >>
        $allYoutubePlaylistsIds = array_map(function ($n) { return $n['id'];}, $allYoutubePlaylists);
        $master = $this->PLAYLISTS_MASTER;
        $allTagsYtId = array();

        foreach ($allPlaylistTags as $tag) {
            $ytPlaylistId = $tag->getProperty('youtube');
            $allTagsYtId[] = $ytPlaylistId;

            if ($ytPlaylistId === null || !in_array($ytPlaylistId, $allYoutubePlaylistsIds)) {
                //If a playlist on PuMuKIT doesn't exist on Youtube, create it.
                if ($master == 'pumukit') {
                    $msg = sprintf('Creating YouTube playlist from tag "%s" (%s) because it doesn\'t exist locally', $tag->getTitle(), $tag->getCod());
                    echo $msg;
                    $this->logger->info($msg);
                    $this->createYoutubePlaylist($tag);
                } elseif ($this->DELETE_PLAYLISTS) {
                    $msg = sprintf('Deleting tag "%s" (%s) because it doesn\'t exist on YouTube', $tag->getTitle(), $tag->getCod());
                    echo $msg;
                    $this->logger->warn($msg);
                    $this->deletePumukitPlaylist($tag);
                }
            } else {
                if ($master == 'pumukit') {
                    $msg = sprintf('Updating YouTube playlist from tag "%s" (%s)', $tag->getTitle(), $tag->getCod());
                    echo $msg;
                    $this->logger->info($msg);
                    $this->updateYoutubePlaylist($tag);
                } else {
                    $msg = sprintf('Updating tag from YouTube playlist "%s" (%s)', $tag->getTitle(), $tag->getCod());
                    echo $msg;
                    $this->logger->info($msg);
                    $this->updatePumukitPlaylist($tag);
                }
            }
        }
        foreach ($allYoutubePlaylists as $ytPlaylist) {
            if (!in_array($ytPlaylist['id'], $allTagsYtId)) {
                if ($master == 'youtube') {
                    $msg = sprintf('Creating tag using YouTube playlist "%s" (%s)', $ytPlaylist['title'], $ytPlaylist['id']);
                    echo $msg;
                    $this->logger->info($msg);
                    $this->createPumukitPlaylist($ytPlaylist);
                } elseif ($this->DELETE_PLAYLISTS) {
                    $msg = sprintf('Deleting YouTube playlist "%s" (%s) because it doesn\'t exist locally', $ytPlaylist['title'], $ytPlaylist['id']);
                    echo $msg;
                    $this->logger->warn($msg);
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
     * @throws \Exception
     */
    private function createYoutubePlaylist(Tag $tag)
    {
        echo "create On Youtube: ".$tag->getTitle($this->ytLocale) . "\n";

        $sResult = $this->youtubeProcessService->createPlaylist($tag->getTitle($this->ytLocale), $this->playlistPrivacyStatus);
        if ($sResult['error']) {
            $errorLog = sprintf('%s [%s] Error in creating in Youtube the playlist from tag with id %s: %s', __CLASS__, __FUNCTION__, $tag->getId(), $sResult['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        } elseif ($sResult['out'] != null) {
            $infoLog = sprintf('%s [%s] Created Youtube Playlist %s for Tag with id %s', __CLASS__, __FUNCTION__, $sResult['out'], $tag->getId());
            $this->logger->addInfo($infoLog);
            $playlistId = $sResult['out'];
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
     *     string $youtubePlaylist['id'] = id of the playlist on youtube.
     *     string $youtubePlaylist['title'] = title of the playlist on youtube.
     *
     * @return Tag
     */
    private function createPumukitPlaylist($youtubePlaylist)
    {
        echo "create On Pumukit: ".$youtubePlaylist['title'] . "\n";
        $metatag = $this->getPlaylistMetaTag();
        $tag = new Tag();
        $tag->setLocale($this->ytLocale);
        $tag->setCod($youtubePlaylist['id']);
        $tag->setTitle($youtubePlaylist['title']);
        $tag->setDescription('Tag playlist generated automatically from youtube. Do not edit.');
        $tag->setProperty('youtube', $youtubePlaylist['id']);
        $tag->setProperty('customfield', 'youtube:text');
        $tag->setProperty('origin', 'youtube');
        $tag->setParent($metatag);
        $this->dm->persist($tag);
        $this->dm->flush();

        return $tag;
    }

    /**
     * Deletes an existing playlist on Youtube given a playlist object.
     * string $youtubePlaylist['id'] = id of the playlist on youtube.
     * string $youtubePlaylist['title'] = title of the playlist on youtube.
     *
     * @param $youtubePlaylist
     * @throws \Exception
     */
    private function deleteYoutubePlaylist($youtubePlaylist)
    {
        echo "delete On Youtube: ".$youtubePlaylist['title'] . "\n";

        $sResult = $this->youtubeProcessService->deletePlaylist($youtubePlaylist['id']);
        if (!isset($sResult['out'])
            && $sResult['error_out']['code'] != '404') {
            $errorLog = sprintf('%s [%s] Error in deleting in Youtube the playlist with id %s: %s', __CLASS__, __FUNCTION__, $youtubePlaylist['id'], $sResult['error_out']);
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
        echo "delete On Pumukit: ".$tag->getTitle($this->ytLocale) . "\n";
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
        echo "update from Pumukit: ".$tag->getTitle($this->ytLocale). "\n";
    }
    private function updatePumukitPlaylist(Tag $tag, $youtubePlaylist = null)
    {
        echo "update from Youtube: ".$tag->getTitle($this->ytLocale). "\n";
    }

    /**
     * Gets an array of 'playlists' with all youtube playlists data.
     *
     * returns array
     */
    public function getAllYoutubePlaylists()
    {
        $res = array();
        $playlist = array();

        $sResult = $this->youtubeProcessService->getAllPlaylist();
        if ($sResult['error']) {
            $errorLog = sprintf('%s [%s] Error in executing getAllPlaylists.py:', __CLASS__, __FUNCTION__, $sResult['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        foreach ($sResult['out'] as $response) {
            $playlist['id'] = $response['id'];
            $playlist['title'] = $response['snippet']['title'];
            $res[ $playlist['id'] ] = $playlist;
        }

        return $res;
    }

    /**
     * Gets an array of 'playlisitems.
     *
     * returns array
     */
    public function getAllYoutubePlaylistItems()
    {
        $res = array();
        $playlist = array();

        $sResult = $this->youtubeProcessService->getAllPlaylist();
        if ($sResult['error']) {
            $errorLog = sprintf('%s [%s] Error in executing getAllPlaylists.py:', __CLASS__, __FUNCTION__, $sResult['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return $sResult['out'];
    }

    /**
     * Add the MultimediaObject to the default playlist tag if criteria are met
     * Current Criteria: - USE_DEFAULT_PLAYLIST == true
     *                   - Multimedia Object doesn't have any playlists tag.
     *
     * @param MultimediaObject $multimediaObject
     * @return int
     * @throws \Exception
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
        $playlistTag->setTitle($this->DEFAULT_PLAYLIST_TITLE, $this->ytLocale);
        $this->dm->persist($playlistTag);
        $this->dm->flush();

        return $playlistTag;
    }

    /**
     *
     * Returns the metaTag for youtube playlists.
     *
     * @return $metatag
     * @throws \Exception
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
     * @param $playlistId
     * @return Tag
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
        if ($this->senderService && $this->senderService->isEnabled()) {
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
                        $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle($this->ytLocale).' '.$this->router->generate('pumukitnewadmin_mms_shortener', array('id' => $mm->getId()), true);
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
                    $body = $body.'<br/> -'.$mm->getId().': '.$mm->getTitle($this->ytLocale).'<br/>';
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
                    $body = $body.'<br/>The video "'.$multimediaObject->getTitle($this->ytLocale).'" has been successfully published into YouTube.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ($cause === 'status removed') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The following video has been removed from YouTube: "'.$multimediaObject->getTitle($this->ytLocale).'"<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ($cause === 'duplicated') {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>YouTube has rejected the upload of the video: "'.$multimediaObject->getTitle($this->ytLocale).'"</br>';
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
    private function getTitleForYoutube(MultimediaObject $multimediaObject, $limit = 100)
    {
        $title = $multimediaObject->getTitle($this->ytLocale);

        if (strlen($title) > $limit) {
            while (strlen($title) > ($limit - 5)) {
                $pos = strrpos($title, ' ', $limit + 1);
                if ($pos !== false) {
                    $title = substr($title, 0, $pos);
                } else {
                    break;
                }
            }
        }
        while (strlen($title) > ($limit - 5)) {
            $title = substr($title, 0, strrpos($title, ' '));
        }
        if (strlen($multimediaObject->getTitle($this->ytLocale)) > ($limit - 5)) {
            $title = $title.'(...)';
        }

        return $title;
    }

    /**
     * Get description for youtube.
     */
    private function getDescriptionForYoutube(MultimediaObject $multimediaObject)
    {
        $series = $multimediaObject->getSeries();
        $break = array('<br />', '<br/>');
        $description = $series->getTitle($this->ytLocale).' - '.$multimediaObject->getTitle($this->ytLocale)."\n".$multimediaObject->getSubtitle($this->ytLocale)."\n".str_replace($break, "\n", $multimediaObject->getDescription($this->ytLocale));

        if(MultimediaObject::STATUS_PUBLISHED == $multimediaObject->getStatus() && $multimediaObject->containsTagWithCod('PUCHWEBTV')) {
            $appInfoLink = $this->router->generate('pumukit_webtv_multimediaobject_index', array('id' => $multimediaObject->getId()), true);
            $description .= '<br /> Video available at: '.$appInfoLink;
        }

        return strip_tags($description);
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
     * @return Youtube
     * @throws \Exception
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
        $sResult = $this->youtubeProcessService->deleteFromList($playlistItem);
        if ($sResult['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
              ."] Error in deleting the Youtube video with id '".$youtube->getId()
              ."' from playlist with id '".$playlistItem."': ".$sResult['error_out'];
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
        return '<iframe width="853" height="480" src="http://www.youtube.com/embed/'.$youtubeId.'" frameborder="0" allowfullscreen></iframe>';
    }
}
