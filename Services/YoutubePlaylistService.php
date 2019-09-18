<?php

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Translation\TranslatorInterface;

class YoutubePlaylistService
{
    /**
     * @var DocumentManager
     */
    private $documentManager;

    /**
     * @var TagService
     */
    private $tagService;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var TranslatorInterface
     */
    private $translator;
    /**
     * @var YoutubeProcessService
     */
    private $youtubeProcessService;
    /**
     * @var YoutubeService
     */
    private $youtubeService;
    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Pumukit\SchemaBundle\Repository\TagRepository
     */
    private $tagRepo;

    /**
     * @var \Doctrine\Common\Persistence\ObjectRepository|\Pumukit\EncoderBundle\Repository\JobRepository
     */
    private $playlistPrivacyStatus;
    private $USE_DEFAULT_PLAYLIST;
    private $DEFAULT_PLAYLIST_COD;
    private $DEFAULT_PLAYLIST_TITLE;
    private $METATAG_PLAYLIST_COD;
    private $PLAYLISTS_MASTER;
    private $DELETE_PLAYLISTS;
    private $ytLocale;
    private $defaultTrackUpload;
    private $kernelRootDir;

    public function __construct(DocumentManager $documentManager, YoutubeService $youtubeService, TagService $tagService, LoggerInterface $logger, TranslatorInterface $translator, YoutubeProcessService $youtubeProcessService, $playlistPrivacyStatus, $locale, $useDefaultPlaylist, $defaultPlaylistCod, $defaultPlaylistTitle, $metatagPlaylistCod, $playlistMaster, $deletePlaylists, $pumukitLocales, $defaultTrackUpload, $kernelRootDir)
    {
        $this->documentManager = $documentManager;
        $this->youtubeService = $youtubeService;
        $this->tagService = $tagService;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->youtubeProcessService = $youtubeProcessService;
        $this->tagRepo = $this->documentManager->getRepository(Tag::class);
        $this->playlistPrivacyStatus = $playlistPrivacyStatus;
        $this->ytLocale = $locale;
        $this->USE_DEFAULT_PLAYLIST = $useDefaultPlaylist;
        $this->DEFAULT_PLAYLIST_COD = $defaultPlaylistCod;
        $this->DEFAULT_PLAYLIST_TITLE = $defaultPlaylistTitle;
        $this->METATAG_PLAYLIST_COD = $metatagPlaylistCod;
        $this->PLAYLISTS_MASTER = $playlistMaster;
        $this->DELETE_PLAYLISTS = $deletePlaylists;
        $this->kernelRootDir = $kernelRootDir;

        $this->defaultTrackUpload = $defaultTrackUpload;
        if (!in_array($this->ytLocale, $pumukitLocales)) {
            $this->ytLocale = $this->translator->getLocale();
        }
    }

    /**
     * Move to list.
     *
     * @param MultimediaObject $multimediaObject
     * @param string           $playlistTagId
     *
     * @throws \Exception
     *
     * @return int
     */
    public function moveToList(MultimediaObject $multimediaObject, $playlistTagId)
    {
        $youtube = $this->youtubeService->getYoutubeDocument($multimediaObject);

        if (null === $playlistTag = $this->tagRepo->find($playlistTagId)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error! The tag with id '".$playlistTagId."' for Youtube Playlist does not exist";
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        if (null === $playlistId = $playlistTag->getProperty('youtube')) {
            $errorLog = sprintf('%s [%s] Error! The tag with id %s doesn\'t have a \'youtube\' property!\n Did you use %s first?', __CLASS__, __FUNCTION__, $playlistTag->getId(), 'syncPlaylistsRelations()');
            $this->logger->error($errorLog);

            throw new \Exception();
        }

        $aResult = $this->youtubeProcessService->insertInToList($youtube, $playlistId, $youtube->getYoutubeAccount(), $multimediaObject->getRank());
        if ($aResult['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in moving the Multimedia Object '".$multimediaObject->getId()."' to Youtube playlist with id '".$playlistId."': ".$aResult['error_out'];
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        if (null != $aResult['out']) {
            $youtube->setPlaylist($playlistId, $aResult['out']);

            if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                $this->tagService->addTagToMultimediaObject($multimediaObject, $playlistTag->getId(), false);
            }
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
        } else {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error in moving the Multimedia Object '".$multimediaObject->getId()."' to Youtube playlist with id '".$playlistId."'";
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        return 0;
    }

    /**
     * Update playlists.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @throws \Exception
     *
     * @return int
     */
    public function updatePlaylists(MultimediaObject $multimediaObject)
    {
        $youtube = $this->youtubeService->getYoutubeDocument($multimediaObject);
        if (Youtube::STATUS_PUBLISHED !== $youtube->getStatus()) {
            return 0;
        }
        $this->checkAndAddDefaultPlaylistTag($multimediaObject);

        foreach ($multimediaObject->getTags() as $embedTag) {
            if (!$embedTag->isDescendantOfByCod($this->METATAG_PLAYLIST_COD)) {
                //This is not the tag you are looking for
                continue;
            }
            $playlistTag = $this->tagRepo->findOneBy(['cod' => $embedTag->getCod()]);

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
                    $this->logger->error($errorLog);

                    throw new \Exception($errorLog);
                }
                $this->youtubeService->deleteFromList($playlistItem, $youtube, $playlistId, false);
            }
        }

        $this->documentManager->persist($youtube);
        $this->documentManager->flush();

        return 0;
    }

    /**
     * Updates the relationship between Tags and Youtube Playlists according to the $this->PLAYLISTS_MASTER
     * configuration. If the master is PuMuKIT, it deletes/creates/updates_metadata of all playlists in Youtube based
     * on existent tags. If the master is Youtube, it deletes/creates/updates_metadata of all tags in PuMuKIT based on
     * existent Youtube playlists.
     *
     * @param bool $dryRun
     *
     * @throws \Exception
     *
     * @return int
     */
    public function syncPlaylistsRelations($dryRun = false)
    {
        if ($this->USE_DEFAULT_PLAYLIST) {
            $this->getOrCreateDefaultTag();
        }
        $youtubeAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => 'YOUTUBE']);
        foreach ($youtubeAccount->getChildren() as $account) {
            $allPlaylistTags = $account->getChildren();
            $login = $account->getProperty('login');

            // If these condition is deleted, syncPlaylistRelations brokes and all videos will be without playlist
            $currentDir = __DIR__.'/../Resources/data/accounts/';

            $secondaryPath = $this->kernelRootDir . '/../app/config/youtube_accounts/'.$login.'.json';
            if (!file_exists($currentDir.$login.'.json') && !file_exists($secondaryPath)) {
                $this->logger->error("There aren't file for account {$login}");

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
            $allTagsYtId = [];

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
                        echo $msg, "\n";
                        $this->logger->info($msg);
                        if (!$dryRun) {
                            $this->createYoutubePlaylist($tag);
                        }
                    } elseif ($this->DELETE_PLAYLISTS) {
                        $msg = sprintf(
                            'Deleting tag "%s" (%s) because it doesn\'t exist on YouTube',
                            $tag->getTitle(),
                            $tag->getCod()
                        );
                        echo $msg, "\n";
                        $this->logger->alert($msg);
                        if (!$dryRun) {
                            $this->deletePumukitPlaylist($tag);
                        }
                    }
                } else {
                    if ('pumukit' == $master) {
                        $msg = sprintf(
                            'Updating YouTube playlist from tag "%s" (%s)',
                            $tag->getTitle(),
                            $tag->getCod()
                        );
                        echo $msg, "\n";
                        $this->logger->info($msg);
                        if (!$dryRun) {
                            $this->updateYoutubePlaylist($tag);
                        }
                    } else {
                        $msg = sprintf(
                            'Updating tag from YouTube playlist "%s" (%s)',
                            $tag->getTitle(),
                            $tag->getCod()
                        );
                        echo $msg, "\n";
                        $this->logger->info($msg);
                        if (!$dryRun) {
                            $this->updatePumukitPlaylist($tag);
                        }
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
                        echo $msg, "\n";
                        $this->logger->info($msg);
                        if (!$dryRun) {
                            $this->createPumukitPlaylist($ytPlaylist);
                        }
                    } elseif ($this->DELETE_PLAYLISTS) {
                        if ('Favorites' == $ytPlaylist['title']) {
                            continue;
                        }

                        $msg = sprintf(
                            'Deleting YouTube playlist "%s" (%s) because it doesn\'t exist locally',
                            $ytPlaylist['title'],
                            $ytPlaylist['id']
                        );
                        echo $msg, "\n";
                        $this->logger->alert($msg);
                        if (!$dryRun) {
                            $this->deleteYoutubePlaylist($ytPlaylist, $login);
                        }
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Gets an array of 'playlists' with all youtube playlists data.
     * returns array.
     *
     * @param string $login
     *
     * @throws \Exception
     *
     * @return array
     */
    public function getAllYoutubePlaylists($login)
    {
        $res = [];
        $playlist = [];

        $aResult = $this->youtubeProcessService->getAllPlaylist($login);
        if ($aResult['error']) {
            $errorLog = sprintf('%s [%s] Error in executing getAllPlaylists.py: %s', __CLASS__, __FUNCTION__, $aResult['error_out']);
            $this->logger->error($errorLog);

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
     * Creates a new playlist in Youtube using the 'tag' metadata.
     *
     * @param Tag $tag
     *
     * @throws \Exception
     */
    protected function createYoutubePlaylist(Tag $tag)
    {
        echo 'create On Youtube: '.$tag->getTitle($this->ytLocale)."\n";

        $playlistTitle = $tag->getTitle($this->ytLocale);
        if (strlen($playlistTitle) > 150) {
            $youtubeTitlePlaylist = substr($playlistTitle, 0, 147);
            $youtubeTitlePlaylist .= '...';
        } else {
            $youtubeTitlePlaylist = $playlistTitle;
        }
        $aResult = $this->youtubeProcessService->createPlaylist($youtubeTitlePlaylist, $this->playlistPrivacyStatus, $tag->getParent()->getProperty('login'));
        if ($aResult['error']) {
            $errorLog = sprintf('%s [%s] Error in creating in Youtube the playlist from tag with id %s: %s', __CLASS__, __FUNCTION__, $tag->getId(), $aResult['error_out']);
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        if (null != $aResult['out']) {
            $infoLog = sprintf('%s [%s] Created Youtube Playlist %s for Tag with id %s', __CLASS__, __FUNCTION__, $aResult['out'], $tag->getId());
            $this->logger->info($infoLog);
            $playlistId = $aResult['out'];
            $tag->setProperty('youtube', $playlistId);
            $tag->setProperty('customfield', 'youtube:text');
            $this->documentManager->persist($tag);
            $this->documentManager->flush();
        } else {
            $errorLog = sprintf('%s [%s] Error! Creating the playlist from tag with id %s', __CLASS__, __FUNCTION__, $tag->getId());

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
     * @throws \Exception
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
        $this->documentManager->persist($tag);
        $this->documentManager->flush();

        return $tag;
    }

    /**
     * Deletes an existing playlist on Youtube given a playlist object.
     * string $youtubePlaylist['id'] = id of the playlist on youtube.
     * string $youtubePlaylist['title'] = title of the playlist on youtube.
     *
     * @param array  $youtubePlaylist
     * @param string $login
     *
     * @throws \Exception
     */
    protected function deleteYoutubePlaylist(array $youtubePlaylist, $login)
    {
        echo 'delete On Youtube: '.$youtubePlaylist['title']."\n";

        $aResult = $this->youtubeProcessService->deletePlaylist($youtubePlaylist['id'], $login);
        if (!isset($aResult['out']) && '404' != $aResult['error_out']['code']) {
            $errorLog = sprintf('%s [%s] Error in deleting in Youtube the playlist with id %s: %s', __CLASS__, __FUNCTION__, $youtubePlaylist['id'], $aResult['error_out']);
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        $infoLog = sprintf('%s [%s] Deleted Youtube Playlist with id %s', __CLASS__, __FUNCTION__, $youtubePlaylist['id']);
        $this->logger->info($infoLog);
    }

    /**
     * Deletes an existing playlist on PuMuKIT. Takes care of deleting all relations left by this tag.
     *
     * @param Tag $tag
     *
     * @throws \Exception
     */
    protected function deletePumukitPlaylist(Tag $tag)
    {
        echo 'delete On Pumukit: '.$tag->getTitle($this->ytLocale)."\n";
        $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->findWithTag($tag);
        foreach ($multimediaObjects as $mmobj) {
            $this->tagService->removeTagFromMultimediaObject($mmobj, $tag->getId());
            $youtube = $this->documentManager->getRepository(Youtube::class)->findOneBy([
                'multimediaObjectId' => $mmobj->getId(),
            ]);
            if (isset($youtube)) {
                $playlist = $youtube->getPlaylist($tag->getProperty('youtube'));
                if (isset($playlist)) {
                    $youtube->removePlaylist($playlist->getId());
                }
            }
        }
        $this->documentManager->remove($tag);
        $this->documentManager->flush();
    }

    //TODO Update Scripts:
    protected function updateYoutubePlaylist(Tag $tag)
    {
        echo 'update from Pumukit: '.$tag->getTitle($this->ytLocale)."\n";
    }

    protected function updatePumukitPlaylist(Tag $tag)
    {
        echo 'update from Youtube: '.$tag->getTitle($this->ytLocale)."\n";
    }

    /**
     * Add the MultimediaObject to the default playlist tag if criteria are met
     * Current Criteria: - USE_DEFAULT_PLAYLIST == true
     *                   - Multimedia Object doesn't have any playlists tag.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @throws \Exception
     *
     * @return int
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
     * @throws \Exception
     *
     * @return Tag
     */
    protected function getOrCreateDefaultTag()
    {
        $playlistTag = $this->tagRepo->findOneBy(['cod' => $this->DEFAULT_PLAYLIST_COD]);
        if (isset($playlistTag)) {
            return $playlistTag;
        }
        $metatagPlaylist = $this->getPlaylistMetaTag();
        $playlistTag = new Tag();
        $playlistTag->setParent($metatagPlaylist);
        $playlistTag->setCod($this->DEFAULT_PLAYLIST_COD);
        $playlistTag->setTitle($this->DEFAULT_PLAYLIST_TITLE);
        $playlistTag->setTitle($this->DEFAULT_PLAYLIST_TITLE, $this->ytLocale);
        $this->documentManager->persist($playlistTag);
        $this->documentManager->flush();

        return $playlistTag;
    }

    /**
     * Returns the metaTag for youtube playlists.
     *
     * @throws \Exception
     *
     * @return mixed
     */
    protected function getPlaylistMetaTag()
    {
        static $metatag = null;
        if (null !== $metatag) {
            return $metatag;
        }

        $metatag = $this->tagRepo->findOneBy(['cod' => $this->METATAG_PLAYLIST_COD]);
        if (!isset($metatag)) {
            $errorLog = sprintf('%s [%s] Error! The METATAG_PLAYLIST with cod:%s for YOUTUBE doesn\'t exist! \n Did you load the tag and set the correct cod in parameters.yml?', __CLASS__, __FUNCTION__, $this->METATAG_PLAYLIST_COD);
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        return $metatag;
    }

    /**
     * Returns a Tag whose youtube property 'youtube' has a $playlistId value.
     *
     * @param string $playlistId
     *
     * @return null|array|Tag
     */
    protected function getTagByYoutubeProperty($playlistId)
    {
        return $this->documentManager->createQueryBuilder(Tag::class)->field('properties.youtube')->equals($playlistId)->getQuery()->getSingleResult();
    }
}
