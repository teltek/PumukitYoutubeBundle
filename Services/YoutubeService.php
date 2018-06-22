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

    protected $dm;
    protected $router;
    protected $tagService;
    protected $logger;
    protected $senderService;
    protected $translator;
    protected $youtubeRepo;
    protected $tagRepo;
    protected $mmobjRepo;
    protected $youtubeProcessService;
    protected $playlistPrivacyStatus;
    protected $ytLocale;
    protected $syncStatus;
    protected $USE_DEFAULT_PLAYLIST;
    protected $DEFAULT_PLAYLIST_COD;
    protected $DEFAULT_PLAYLIST_TITLE;
    protected $METATAG_PLAYLIST_COD;
    protected $PLAYLISTS_MASTER;
    protected $DELETE_PLAYLISTS;
    protected $defaultTrackUpload;
    protected $generateSbs;
    protected $sbsProfileName;
    protected $jobService;
    protected $jobRepo;
    protected $opencastService;

    public static $status = array(
        0 => 'public',
        1 => 'private',
        2 => 'unlisted',
    );

    public function __construct(DocumentManager $documentManager, Router $router, TagService $tagService, LoggerInterface $logger, SenderService $senderService = null, TranslatorInterface $translator, YoutubeProcessService $youtubeProcessService, $playlistPrivacyStatus, $locale, $useDefaultPlaylist, $defaultPlaylistCod, $defaultPlaylistTitle, $metatagPlaylistCod, $playlistMaster, $deletePlaylists, $pumukitLocales, $youtubeSyncStatus, $defaultTrackUpload, $generateSbs, $sbsProfileName, $jobService, $opencastService)
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
        $this->jobRepo = $this->dm->getRepository('PumukitEncoderBundle:Job');
        $this->playlistPrivacyStatus = $playlistPrivacyStatus;
        $this->ytLocale = $locale;
        $this->syncStatus = $youtubeSyncStatus;
        $this->USE_DEFAULT_PLAYLIST = $useDefaultPlaylist;
        $this->DEFAULT_PLAYLIST_COD = $defaultPlaylistCod;
        $this->DEFAULT_PLAYLIST_TITLE = $defaultPlaylistTitle;
        $this->METATAG_PLAYLIST_COD = $metatagPlaylistCod;
        $this->PLAYLISTS_MASTER = $playlistMaster;
        $this->DELETE_PLAYLISTS = $deletePlaylists;
        $this->generateSbs = $generateSbs;
        $this->sbsProfileName = $sbsProfileName;
        $this->jobService = $jobService;
        $this->opencastService = $opencastService;

        $this->defaultTrackUpload = $defaultTrackUpload;
        if (!in_array($this->ytLocale, $pumukitLocales)) {
            $this->ytLocale = $translator->getLocale();
        }
    }

    /**
     * Check pending encoder jobs for a multimedia object.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return bool
     */
    public function hasPendingJobs(MultimediaObject $multimediaObject)
    {
        $repo = $this->dm->getRepository('PumukitEncoderBundle:Job');
        $jobs = $repo->findNotFinishedByMultimediaObjectId($multimediaObject->getId());

        return 0 != count($jobs);
    }

    /**
     * Get track to upload into YouTube.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return null|Track
     */
    public function getTrack(MultimediaObject $multimediaObject)
    {
        $track = null;
        $opencastId = $multimediaObject->getProperty('opencast');
        if (null !== $opencastId) {
            $track = $multimediaObject->getFilteredTrackWithTags(array(), array('sbs'), array(), array(), false);
        } else {
            $track = $multimediaObject->getTrackWithTag($this->defaultTrackUpload);
        }
        if ((null === $track) || ($track->isOnlyAudio())) {
            $track = $multimediaObject->getTrackWithTag('master');
        }

        return $track;
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
     *
     * @throws \Exception
     */
    public function upload(MultimediaObject $multimediaObject, $category = 27, $privacy = 'private', $force = false)
    {
        $track = null;
        if ($multimediaObject->isMultistream()) {
            $track = $multimediaObject->getFilteredTrackWithTags(array(), array($this->sbsProfileName), array(), array(), false);
            if (!$track) {
                return $this->generateSbsTrack($multimediaObject);
            }
        } //Or array('sbs','html5') ??
        else {
            $track = $multimediaObject->getTrackWithTag($this->defaultTrackUpload); //TODO get Only the video track with tag html5
        }
        if ((null === $track) || ($track->isOnlyAudio())) {
            $track = $multimediaObject->getTrackWithTag('master');
        }
        if ((null === $track) || ($track->isOnlyAudio())) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error, the Multimedia Object with id '".$multimediaObject->getId()."' has no track master.";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $trackPath = $track->getPath();
        if (!file_exists($trackPath)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error, there is no file '.$trackPath;
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId());
        if (!$youtube) {
            $youtube = new Youtube();
            $youtube->setMultimediaObjectId($multimediaObject->getId());

            $youtubeTag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => 'YOUTUBE'));
            $youtubeTagAccount = null;
            foreach ($multimediaObject->getTags() as $tag) {
                if ($tag->isChildOf($youtubeTag)) {
                    $tagAccount = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => $tag->getCod()));
                    $youtube->setYoutubeAccount($tagAccount->getProperty('login'));
                    $youtubeTagAccount = $tagAccount;
                }
            }

            if (!$youtubeTagAccount) {
                $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error, there aren\'t account on '.$multimediaObject->getId();
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
            $this->dm->persist($youtube);

            $login = $youtubeTagAccount->getProperty('login');
        } else {
            $login = $youtube->getYoutubeAccount();
        }

        $multimediaObject->setProperty('youtube', $youtube->getId());
        $this->dm->persist($multimediaObject);

        $title = $this->getTitleForYoutube($multimediaObject);
        $description = $this->getDescriptionForYoutube($multimediaObject);
        $tags = $this->getTagsForYoutube($multimediaObject);

        $aResult = $this->youtubeProcessService->upload($trackPath, $title, $description, $category, $tags, $privacy, $login);
        if ($aResult['error']) {
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error in the upload: '.$aResult['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setYoutubeId($aResult['out']['id']);
        $youtube->setLink('https://www.youtube.com/watch?v='.$aResult['out']['id']);
        $multimediaObject->setProperty('youtubeurl', $youtube->getLink());
        $this->dm->persist($multimediaObject);
        if ($aResult['out']['status'] == 'uploaded') {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
        }

        $code = $this->getEmbed($aResult['out']['id']);
        $youtube->setEmbed($code);
        $youtube->setForce($force);

        $now = new \DateTime('now');
        $youtube->setSyncMetadataDate($now);
        $youtube->setUploadDate($now);
        $this->dm->persist($youtube);
        $this->dm->flush();
        $youtubeTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] There is no Youtube tag defined with code PUCHYOUTUBE.';
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return 0;
    }

    /**
     * Move to list.
     *
     * @param MultimediaObject $multimediaObject
     * @param                  $playlistTagId
     *
     * @return int
     *
     * @throws \Exception
     */
    public function moveToList(MultimediaObject $multimediaObject, $playlistTagId)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        if (null === $playlistTag = $this->tagRepo->find($playlistTagId)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error! The tag with id '".$playlistTagId."' for Youtube Playlist does not exist";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        if (null === $playlistId = $playlistTag->getProperty('youtube')) {
            $errorLog = sprintf('%s [%s] Error! The tag with id %s doesn\'t have a \'youtube\' property!\n Did you use %s first?', __CLASS__, __FUNCTION__, $playlistTag->getId(), 'syncPlaylistsRelations()');
            $this->logger->addError($errorLog);
            throw new \Exception();
        }

        $aResult = $this->youtubeProcessService->insertInToList($youtube, $playlistId, $youtube->getYoutubeAccount());
        if ($aResult['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in moving the Multimedia Object '".$multimediaObject->getId()."' to Youtube playlist with id '".$playlistId."': ".$aResult['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        if (null != $aResult['out']) {
            $youtube->setPlaylist($playlistId, $aResult['out']);
            if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                $this->tagService->addTagToMultimediaObject($multimediaObject, $playlistTag->getId(), false);
            }
            $this->dm->persist($youtube);
            $this->dm->flush();
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in moving the Multimedia Object '".$multimediaObject->getId()."' to Youtube playlist with id '".$playlistId."'";
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
     *
     * @throws \Exception
     */
    public function delete(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }
        $aResult = $this->youtubeProcessService->deleteVideo($youtube, $youtube->getYoutubeAccount());
        if ($aResult['error'] && (false === strpos($aResult['error_out'], 'No se ha encontrado el video'))) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()."' and mongo id '".$youtube->getId()."': ".$aResult['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->setForce(false);
        $this->dm->persist($youtube);
        $multimediaObject->removeProperty('youtube');
        $multimediaObject->removeProperty('youtubeurl');

        $this->dm->persist($multimediaObject);

        $this->dm->flush();
        $youtubeEduTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        $youtubeTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);
        if (null != $youtubeTag) {
            if ($multimediaObject->containsTag($youtubeEduTag)) {
                $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
            }
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] There is no Youtube tag defined with code '".self::PUB_CHANNEL_YOUTUBE."'";
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
     *
     * @throws \Exception
     */
    public function deleteOrphan(Youtube $youtube)
    {
        foreach ($youtube->getPlaylists() as $playlistId => $playlistItem) {
            $this->deleteFromList($playlistItem, $youtube, $playlistId);
        }

        $aResult = $this->youtubeProcessService->deleteVideo($youtube, $youtube->getYoutubeAccount());
        if ($aResult['error'] && (false === strpos($aResult['error_out'], 'No se ha encontrado el video'))) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in deleting the YouTube video with id '".$youtube->getYoutubeId()."' and mongo id '".$youtube->getId()."': ".$aResult['error_out'];
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
     *
     * @throws \Exception
     */
    public function updateMetadata(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);

        if (Youtube::STATUS_PUBLISHED === $youtube->getStatus()) {
            $title = $this->getTitleForYoutube($multimediaObject);
            $description = $this->getDescriptionForYoutube($multimediaObject);
            $tags = $this->getTagsForYoutube($multimediaObject);

            $status = null;
            if ($this->syncStatus) {
                $status = self::$status[$multimediaObject->getStatus()];
            }

            $aResult = $this->youtubeProcessService->updateVideo($youtube, $title, $description, $tags, $status, $youtube->getYoutubeAccount());
            if ($aResult['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in updating metadata for Youtube video with id '".$youtube->getId()."': ".$aResult['error_out'];
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
     *
     * @throws \Exception
     */
    public function updateStatus(Youtube $youtube)
    {
        $multimediaObject = $this->mmobjRepo->find($youtube->getMultimediaObjectId());
        if (null == $multimediaObject) {
            // TODO remove Youtube Document ?????
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error, there is no MultimediaObject referenced from YouTube document with id '".$youtube->getId()."'";
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        if (null === $youtube->getYoutubeId()) {
            $youtube->setStatus(Youtube::STATUS_ERROR);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] The object Youtube with id: '.$youtube->getId().' does not have a Youtube ID variable set.';
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        $aResult = $this->youtubeProcessService->getData('status', $youtube->getYoutubeId(), $youtube->getYoutubeAccount());

        // NOTE: If the video has been removed, it returns 404 instead of 200 with 'not found Video'
        if ($aResult['error']) {
            if (strpos($aResult['error_out'], 'was not found.')) {
                $data = array(
                    'multimediaObject' => $multimediaObject,
                    'youtube' => $youtube,
                );
                $this->sendEmail('status removed', $data, array(), array());
                $youtube->setStatus(Youtube::STATUS_REMOVED);
                $this->dm->persist($youtube);
                $youtubeEduTag = $this->tagRepo->findOneByCod(self::PUB_CHANNEL_YOUTUBE);

                if (null !== $youtubeEduTag) {
                    if ($multimediaObject->containsTag($youtubeEduTag)) {
                        $this->tagService->removeTagFromMultimediaObject($multimediaObject, $youtubeEduTag->getId());
                    }
                } else {
                    $errorLog = __CLASS__.' ['.__FUNCTION__."] There is no Youtube tag defined with code '".self::PUB_CHANNEL_YOUTUBE."'";
                    $this->logger->addWarning($errorLog);
                    /*throw new \Exception($errorLog);*/
                }
                $this->dm->flush();

                return 0;
            } else {
                $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in verifying the status of the video from youtube with id '".$youtube->getYoutubeId()."' and mongo id '".$youtube->getId()."':  ".$aResult['error_out'];
                $this->logger->addError($errorLog);
                throw new \Exception($errorLog);
            }
        }
        if (('processed' == $aResult['out']) && (Youtube::STATUS_PROCESSING == $youtube->getStatus())) {
            $youtube->setStatus(Youtube::STATUS_PUBLISHED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array(
                'multimediaObject' => $multimediaObject,
                'youtube' => $youtube,
            );
            $this->sendEmail('finished publication', $data, array(), array());
        } elseif ('uploaded' == $aResult['out']) {
            $youtube->setStatus(Youtube::STATUS_PROCESSING);
            $this->dm->persist($youtube);
            $this->dm->flush();
        } elseif (('rejected' == $aResult['out']) && ('duplicate' == $aResult['rejectedReason']) && (Youtube::STATUS_DUPLICATED != $youtube->getStatus())) {
            $youtube->setStatus(Youtube::STATUS_DUPLICATED);
            $this->dm->persist($youtube);
            $this->dm->flush();
            $data = array(
                'multimediaObject' => $multimediaObject,
                'youtube' => $youtube,
            );
            $this->sendEmail('duplicated', $data, array(), array());
        }

        return 0;
    }

    /**
     * Update Status.
     *
     * @param $yid
     * @param $login
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function getVideoMeta($yid, $login)
    {
        $aResult = $this->youtubeProcessService->getData('status', $yid, $login);
        if ($aResult['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error getting meta from YouTube id'.$yid.': '.$aResult['error_out'];
            $this->logger->error($errorLog);
            throw new \Exception($errorLog);
        }

        return $aResult;
    }

    /**
     * Update playlists.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return int
     *
     * @throws \Exception
     */
    public function updatePlaylists(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        if (!isset($youtube) || Youtube::STATUS_PUBLISHED !== $youtube->getStatus()) {
            return 0;
        }
        $this->checkAndAddDefaultPlaylistTag($multimediaObject);
        foreach ($multimediaObject->getTags() as $embedTag) {
            if (!$embedTag->isDescendantOfByCod($this->METATAG_PLAYLIST_COD)) {
                //This is not the tag you are looking for
                continue;
            }
            $playlistTag = $this->tagRepo->findOneByCod($embedTag->getCod());

            if (!$playlistTag->getProperty('youtube_playlist')) {
                continue;
            }
            $playlistId = $playlistTag->getProperty('youtube');

            if (!isset($playlistId) || !array_key_exists($playlistId, $youtube->getPlaylists())) {
                //If the tag doesn't exist on youtube playlists
                $this->moveToList($multimediaObject, $playlistTag->getId());
            }
        }
        foreach ($youtube->getPlaylists() as $playlistId => $playlistRel) {
            $playlistTag = $this->getTagByYoutubeProperty($playlistId);
            //If the tag doesn't exist in PuMuKIT
            if (null === $playlistTag) {
                $errorLog = sprintf('%s [%s] Error! The tag with id %s => %s for Youtube Playlist does not exist', __CLASS__, __FUNCTION__, $playlistId, $playlistRel);
                $this->logger->warning($errorLog);
                continue;
            }
            if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                //If the mmobj doesn't have this tag
                $playlistItem = $youtube->getPlaylist($playlistId);
                if (null === $playlistItem) {
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
     * Updates the relationship between Tags and Youtube Playlists according to the $this->PLAYLISTS_MASTER
     * configuration. If the master is PuMuKIT, it deletes/creates/updates_metadata of all playlists in Youtube based
     * on existent tags. If the master is Youtube, it deletes/creates/updates_metadata of all tags in PuMuKIT based on
     * existent Youtube playlists.
     *
     * @return int
     */
    public function syncPlaylistsRelations()
    {
        if ($this->USE_DEFAULT_PLAYLIST) {
            $this->getOrCreateDefaultTag();
        }
        $youtubeAccount = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => 'YOUTUBE'));
        foreach ($youtubeAccount->getChildren() as $account) {
            $allPlaylistTags = $account->getChildren();
            $login = $account->getProperty('login');

            /* If these condition is deleted, syncPlaylistRelations brokes and all videos will be without playlist */
            $currentDir = __DIR__.'/../Resources/data/accounts/';
            if (!file_exists($currentDir.$login.'.json')) {
                $this->logger->error("There aren't file for account $login");
                continue;
            }

            $allYoutubePlaylists = $this->getAllYoutubePlaylists(
                $login
            ); //Returns array with all neccessary, list(['id','title'])
            //REFACTOR THIS ARRAY_MAP >>
            $allYoutubePlaylistsIds = array_map(
                function ($n) {
                    return $n['id'];
                },
                $allYoutubePlaylists
            );
            $master = $this->PLAYLISTS_MASTER;
            $allTagsYtId = array();

            foreach ($allPlaylistTags as $tag) {
                $ytPlaylistId = $tag->getProperty('youtube');
                $allTagsYtId[] = $ytPlaylistId;

                if (null === $ytPlaylistId || !in_array($ytPlaylistId, $allYoutubePlaylistsIds)) {
                    //If a playlist on PuMuKIT doesn't exist on Youtube, create it.
                    if ('pumukit' == $master) {
                        $msg = sprintf(
                            'Creating YouTube playlist from tag "%s" (%s) because it doesn\'t exist locally',
                            $tag->getTitle(),
                            $tag->getCod()
                        );
                        echo $msg;
                        $this->logger->info($msg);
                        $this->createYoutubePlaylist($tag);
                    } elseif ($this->DELETE_PLAYLISTS) {
                        $msg = sprintf(
                            'Deleting tag "%s" (%s) because it doesn\'t exist on YouTube',
                            $tag->getTitle(),
                            $tag->getCod()
                        );
                        echo $msg;
                        $this->logger->alert($msg);
                        $this->deletePumukitPlaylist($tag);
                    }
                } else {
                    if ('pumukit' == $master) {
                        $msg = sprintf(
                            'Updating YouTube playlist from tag "%s" (%s)',
                            $tag->getTitle(),
                            $tag->getCod()
                        );
                        echo $msg;
                        $this->logger->info($msg);
                        $this->updateYoutubePlaylist($tag);
                    } else {
                        $msg = sprintf(
                            'Updating tag from YouTube playlist "%s" (%s)',
                            $tag->getTitle(),
                            $tag->getCod()
                        );
                        echo $msg;
                        $this->logger->info($msg);
                        $this->updatePumukitPlaylist($tag);
                    }
                }
            }
            foreach ($allYoutubePlaylists as $ytPlaylist) {
                if (!in_array($ytPlaylist['id'], $allTagsYtId)) {
                    if ('youtube' == $master) {
                        $msg = sprintf(
                            'Creating tag using YouTube playlist "%s" (%s)',
                            $ytPlaylist['title'],
                            $ytPlaylist['id']
                        );
                        echo $msg;
                        $this->logger->info($msg);
                        $this->createPumukitPlaylist($ytPlaylist);
                    } elseif ($this->DELETE_PLAYLISTS) {
                        if ('Favorites' == $ytPlaylist['title']) {
                            continue;
                        }

                        $msg = sprintf(
                            'Deleting YouTube playlist "%s" (%s) because it doesn\'t exist locally',
                            $ytPlaylist['title'],
                            $ytPlaylist['id']
                        );
                        echo $msg;
                        $this->logger->alert($msg);
                        $this->deleteYoutubePlaylist($ytPlaylist, $login);
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Creates a new playlist in Youtube using the 'tag' metadata.
     *
     * @param Tag $tag
     *
     * @throws \Exception
     */
    protected function createYoutubePlaylist(Tag $tag)
    {
        echo 'create On Youtube: '.$tag->getTitle($this->ytLocale)."\n";

        $aResult = $this->youtubeProcessService->createPlaylist($tag->getTitle($this->ytLocale), $this->playlistPrivacyStatus, $tag->getParent()->getProperty('login'));
        if ($aResult['error']) {
            $errorLog = sprintf('%s [%s] Error in creating in Youtube the playlist from tag with id %s: %s', __CLASS__, __FUNCTION__, $tag->getId(), $aResult['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        } elseif (null != $aResult['out']) {
            $infoLog = sprintf('%s [%s] Created Youtube Playlist %s for Tag with id %s', __CLASS__, __FUNCTION__, $aResult['out'], $tag->getId());
            $this->logger->addInfo($infoLog);
            $playlistId = $aResult['out'];
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
     *                               string $youtubePlaylist['title'] = title of the playlist on youtube
     *
     * @return Tag
     */
    protected function createPumukitPlaylist($youtubePlaylist)
    {
        echo 'create On Pumukit: '.$youtubePlaylist['title']."\n";
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
     * @param string $login
     *
     * @throws \Exception
     */
    protected function deleteYoutubePlaylist($youtubePlaylist, $login)
    {
        echo 'delete On Youtube: '.$youtubePlaylist['title']."\n";

        $aResult = $this->youtubeProcessService->deletePlaylist($youtubePlaylist['id'], $login);
        if (!isset($aResult['out']) && $aResult['error_out']['code'] != '404') {
            $errorLog = sprintf('%s [%s] Error in deleting in Youtube the playlist with id %s: %s', __CLASS__, __FUNCTION__, $youtubePlaylist['id'], $aResult['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $infoLog = sprintf('%s [%s] Deleted Youtube Playlist with id %s', __CLASS__, __FUNCTION__, $youtubePlaylist['id']);
        $this->logger->addInfo($infoLog);
    }

    /**
     * Deletes an existing playlist on PuMuKIT. Takes care of deleting all relations left by this tag.
     *
     * @param Tag $tag
     */
    protected function deletePumukitPlaylist(Tag $tag)
    {
        echo 'delete On Pumukit: '.$tag->getTitle($this->ytLocale)."\n";
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
    protected function updateYoutubePlaylist(Tag $tag)
    {
        echo 'update from Pumukit: '.$tag->getTitle($this->ytLocale)."\n";
    }

    protected function updatePumukitPlaylist(Tag $tag, $youtubePlaylist = null)
    {
        echo 'update from Youtube: '.$tag->getTitle($this->ytLocale)."\n";
    }

    /**
     * Gets an array of 'playlists' with all youtube playlists data.
     * returns array.
     *
     * @param string $login
     *
     * @return array
     *
     * @throws \Exception
     */
    public function getAllYoutubePlaylists($login)
    {
        $res = array();
        $playlist = array();

        $aResult = $this->youtubeProcessService->getAllPlaylist($login);
        if ($aResult['error']) {
            $errorLog = sprintf('%s [%s] Error in executing getAllPlaylists.py:', __CLASS__, __FUNCTION__, $aResult['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        foreach ($aResult['out'] as $response) {
            $playlist['id'] = $response['id'];
            $playlist['title'] = $response['snippet']['title'];
            $res[$playlist['id']] = $playlist;
        }

        return $res;
    }

    /**
     * Gets an array of 'playlisitems.
     * returns array.
     *
     * @param string $login
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function getAllYoutubePlaylistItems($login)
    {
        $aResult = $this->youtubeProcessService->getAllPlaylist($login);
        if ($aResult['error']) {
            $errorLog = sprintf('%s [%s] Error in executing getAllPlaylists.py:', __CLASS__, __FUNCTION__, $aResult['error_out']);
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }

        return $aResult['out'];
    }

    /**
     * Add the MultimediaObject to the default playlist tag if criteria are met
     * Current Criteria: - USE_DEFAULT_PLAYLIST == true
     *                   - Multimedia Object doesn't have any playlists tag.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return int
     *
     * @throws \Exception
     */
    protected function checkAndAddDefaultPlaylistTag(MultimediaObject $multimediaObject)
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

        return 0;
    }

    /**
     * Returns the default tag. If it doesn't exist, it creates it first.
     *
     * @return Tag
     */
    protected function getOrCreateDefaultTag()
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
     * Returns the metaTag for youtube playlists.
     *
     * @return $metatag
     *
     * @throws \Exception
     */
    protected function getPlaylistMetaTag()
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
     *
     * @return array|object
     */
    protected function getTagByYoutubeProperty($playlistId)
    {
        //return $this->tagRepo->getTagByProperty('youtube', $playlistId); //I like this option more (yet unimplemented)
        return $this->tagRepo->createQueryBuilder()->field('properties.youtube')->equals($playlistId)->getQuery()->getSingleResult();
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
                $emailTo = $this->senderService->getAdminEmail();
                $template = 'PumukitNotificationBundle:Email:notification.html.twig';
                $parameters = array(
                    'subject' => $subject,
                    'body' => $body,
                    'sender_name' => $this->senderService->getSenderName(),
                );
                $output = $this->senderService->sendNotification($emailTo, $subject, $template, $parameters, $error);
                if (0 < $output) {
                    if (is_array($emailTo)) {
                        foreach ($emailTo as $email) {
                            $infoLog = __CLASS__.' ['.__FUNCTION__.'] Sent notification email to "'.$email.'"';
                            $this->logger->addInfo($infoLog);
                        }
                    } else {
                        $infoLog = __CLASS__.' ['.__FUNCTION__.'] Sent notification email to "'.$emailTo.'"';
                        $this->logger->addInfo($infoLog);
                    }
                } else {
                    $infoLog = __CLASS__.' ['.__FUNCTION__.'] Unable to send notification email to "'.$emailTo.'", '.$output.'email(s) were sent.';
                    $this->logger->addInfo($infoLog);
                }

                return $output;
            }
        }

        return false;
    }

    /**
     * @param string $cause
     *
     * @return string
     */
    protected function buildEmailSubject($cause = '')
    {
        $subject = ucfirst($cause).' of YouTube video(s)';

        return $subject;
    }

    /**
     * @param string $cause
     * @param array  $succeed
     * @param array  $failed
     * @param array  $errors
     *
     * @return string
     */
    protected function buildEmailBody($cause = '', $succeed = array(), $failed = array(), $errors = array())
    {
        $statusUpdate = array(
            'finished publication',
            'status removed',
            'duplicated',
        );
        $body = '';
        if (!empty($succeed)) {
            if (in_array($cause, $statusUpdate)) {
                $body = $this->buildStatusUpdateBody($cause, $succeed);
            } else {
                $body = $body.'<br/>The following videos were '.$cause.('e' === substr($cause, -1)) ? '' : 'e'.'d to Youtube:<br/>';
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

    /**
     * @param string $cause
     * @param array  $succeed
     *
     * @return string
     */
    protected function buildStatusUpdateBody($cause = '', $succeed = array())
    {
        $body = '';
        if ((array_key_exists('multimediaObject', $succeed)) && (array_key_exists('youtube', $succeed))) {
            $multimediaObject = $succeed['multimediaObject'];
            $youtube = $succeed['youtube'];
            if ('finished publication' === $cause) {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The video "'.$multimediaObject->getTitle($this->ytLocale).'" has been successfully published into YouTube.<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ('status removed' === $cause) {
                if ($multimediaObject instanceof MultimediaObject) {
                    $body = $body.'<br/>The following video has been removed from YouTube: "'.$multimediaObject->getTitle($this->ytLocale).'"<br/>';
                }
                if ($youtube instanceof Youtube) {
                    $body = $body.'<br/>'.$youtube->getLink().'<br/>';
                }
            } elseif ('duplicated' === $cause) {
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

    /**
     * @param array $errors
     *
     * @return bool
     */
    protected function getError($errors = array())
    {
        if (!empty($errors)) {
            return true;
        }

        return false;
    }

    /**
     * Get title for youtube.
     *
     * @param MultimediaObject $multimediaObject
     * @param int              $limit
     *
     * @return bool|string
     */
    protected function getTitleForYoutube(MultimediaObject $multimediaObject, $limit = 100)
    {
        $title = $multimediaObject->getTitle($this->ytLocale);

        if (strlen($title) > $limit) {
            while (strlen($title) > ($limit - 5)) {
                $pos = strrpos($title, ' ', $limit + 1);
                if (false !== $pos) {
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
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return string
     */
    protected function getDescriptionForYoutube(MultimediaObject $multimediaObject)
    {
        $series = $multimediaObject->getSeries();
        $break = array(
            '<br />',
            '<br/>',
        );
        $linkLabel = 'Video available at:';
        $linkLabelI18n = $this->translator->trans($linkLabel, array(), null, $this->ytLocale);

        $recDateLabel = 'Recording date';
        $recDateI18N = $this->translator->trans($recDateLabel, array(), null, $this->ytLocale);

        $roles = $multimediaObject->getRoles();
        $addPeople = $this->translator->trans('Participating').':'."\n";
        $bPeople = false;
        foreach ($roles as $role) {
            if ($role->getDisplay()) {
                foreach ($role->getPeople() as $person) {
                    $person = $this->dm->getRepository('PumukitSchemaBundle:Person')->findOneById(
                        new \MongoId($person->getId())
                    );
                    $person->setLocale($this->ytLocale);
                    $addPeople .= $person->getHName().' '.$person->getInfo()."\n";
                    $bPeople = true;
                }
            }
        }

        $recDate = $multimediaObject->getRecordDate()->format('d-m-Y');
        if ($series->isHide()) {
            $description = $multimediaObject->getTitle($this->ytLocale)."\n".
                $multimediaObject->getSubtitle($this->ytLocale)."\n".
                $recDateI18N.': '.$recDate."\n".
                str_replace($break, "\n", $multimediaObject->getDescription($this->ytLocale))."\n"
            ;
        } else {
            $description = $multimediaObject->getTitle($this->ytLocale)."\n".
                $multimediaObject->getSubtitle($this->ytLocale)."\n".
                $this->translator->trans('i18n.one.Series', array(), null, $this->ytLocale).': '.$series->getTitle($this->ytLocale)."\n".
                $recDateI18N.': '.$recDate."\n".
                str_replace($break, "\n", $multimediaObject->getDescription($this->ytLocale))."\n"
                ;
        }

        if ($bPeople) {
            $description .= $addPeople."\n";
        }

        if (MultimediaObject::STATUS_PUBLISHED == $multimediaObject->getStatus() && $multimediaObject->containsTagWithCod('PUCHWEBTV')) {
            $appInfoLink = $this->router->generate('pumukit_webtv_multimediaobject_index', array('id' => $multimediaObject->getId()), true);
            $description .= '<br /> '.$linkLabelI18n.' '.$appInfoLink;
        }

        return strip_tags($description);
    }

    /**
     * Get tags for youtube.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return array
     */
    protected function getTagsForYoutube(MultimediaObject $multimediaObject)
    {
        return $multimediaObject->getKeywords($this->ytLocale);

        /* Se matiene comentado el código por si en algún momento un usuario decide devolver por defecto ciertas keywords fijas a sus videos. */
        //$numbers = array('1', '2', '3', '4', '5', '6', '7', '8', '9', '0');
        // TODO CMAR
        //$tags = str_replace($numbers, '', $multimediaObject->getKeyword()) . ', CMAR, Mar, Galicia, Portugal, Eurorregión, Campus, Excelencia, Internacional';
        //$tags = str_replace($numbers, '', $multimediaObject->getKeyword());
        //return $tags;
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
    public function getYoutubeDocument(MultimediaObject $multimediaObject)
    {
        $youtube = $this->youtubeRepo->findOneByMultimediaObjectId($multimediaObject->getId());
        if (null === $youtube) {
            $youtube = $this->fixRemovedYoutubeDocument($multimediaObject);
            $trace = debug_backtrace();
            $caller = $trace[1];
            $errorLog = 'Error, there was no YouTube data of the Multimedia Object '.$multimediaObject->getId().' Created new Youtube document with id "'.$youtube->getId().'"';
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
     *
     * @throws \Exception
     */
    protected function fixRemovedYoutubeDocument(MultimediaObject $multimediaObject)
    {
        //Tries to find the 'youtubeurl' property to recreate the Youtube Document
        $youtubeUrl = $multimediaObject->getProperty('youtubeurl');
        if (null === $youtubeUrl) {
            $errorLog = "PROPERTY 'youtubeurl' for the MultimediaObject id=".$multimediaObject->getId().' DOES NOT EXIST. ¿Is this multimediaObject supposed to be on Youtube?';
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] '.$errorLog;
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        //Tries to get the youtubeId from the youtubeUrl
        $arr = array();
        parse_str(parse_url($youtubeUrl, PHP_URL_QUERY), $arr);
        $youtubeId = isset($arr['v']) ? $arr['v'] : null;

        if (null === $youtubeId) {
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

        $youtubeTag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => 'YOUTUBE'));
        foreach ($multimediaObject->getTags() as $tag) {
            if ($youtubeTag->getId() === $tag->getParent()) {
                $youtube->setYoutubeAccount($youtubeTag->getProperty('login'));
            }
        }

        $file_headers = @get_headers($multimediaObject->getProperty('youtubeurl'));
        if ('HTTP/1.0 200 OK' === $file_headers[0]) {
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

    protected function deleteFromList($playlistItem, $youtube, $playlistId, $doFlush = true)
    {
        $aResult = $this->youtubeProcessService->deleteFromList($playlistItem, $youtube->getYoutubeAccount());
        if ($aResult['error'] && (false === strpos($aResult['error_out'], 'Playlist item not found'))) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in deleting the Youtube video with id '".$youtube->getId()."' from playlist with id '".$playlistItem."': ".$aResult['error_out'];
            $this->logger->addError($errorLog);
            throw new \Exception($errorLog);
        }
        $youtube->removePlaylist($playlistId);
        $this->dm->persist($youtube);
        if ($doFlush) {
            $this->dm->flush();
        }
        $infoLog = __CLASS__.' ['.__FUNCTION__."] Removed playlist with youtube id '".$playlistId."' and relation of playlist item id '".$playlistItem."' from Youtube document with Mongo id '".$youtube->getId()."'";
        $this->logger->addInfo($infoLog);
    }

    /**
     * GetEmbed
     * Returns the html embed (iframe) code for a given youtubeId.
     *
     * @param string $youtubeId
     *
     * @return string
     */
    protected function getEmbed($youtubeId)
    {
        return '<iframe width="853" height="480" src="http://www.youtube.com/embed/'.$youtubeId.'" frameborder="0" allowfullscreen></iframe>';
    }

    protected function generateSbsTrack(MultimediaObject $multimediaObject)
    {
        if ($this->generateSbs && $this->sbsProfileName) {
            if ($multimediaObject->getProperty('opencast')) {
                return $this->generateSbsTrackForOpencast($multimediaObject);
            }
            $job = $this->jobRepo->findOneBy(array('mm_id' => $multimediaObject->getId(), 'profile' => $this->sbsProfileName));
            if ($job) {
                return 0;
            }
            $tracks = $multimediaObject->getTracks();
            if (!$tracks) {
                return 0;
            }
            $track = $tracks[0];
            $path = $track->getPath();
            $language = $track->getLanguage() ? $track->getLanguage() : \Locale::getDefault();
            $job = $this->jobService->addJob($path, $this->sbsProfileName, 2, $multimediaObject, $language, array(), array(), $track->getDuration());
        }

        return 0;
    }

    protected function generateSbsTrackForOpencast(MultimediaObject $multimediaObject)
    {
        if ($this->opencastService) {
            $this->opencastService->generateSbsTrack($multimediaObject);
        }

        return 0;
    }
}
