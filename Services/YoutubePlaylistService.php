<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Contracts\Translation\TranslatorInterface;

class YoutubePlaylistService
{
    private $documentManager;
    private $tagService;
    private $logger;
    private $translator;
    private $youtubeProcessService;
    private $configurationService;
    private $youtubeService;
    private $ytLocale;
    private $kernelRootDir;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeService $youtubeService,
        TagService $tagService,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        YoutubeProcessService $youtubeProcessService,
        YoutubeConfigurationService $configurationService,
        array $pumukitLocales,
        string $kernelRootDir
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeService = $youtubeService;
        $this->tagService = $tagService;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->youtubeProcessService = $youtubeProcessService;
        $this->configurationService = $configurationService;
        $this->kernelRootDir = $kernelRootDir;

        $this->ytLocale = $this->configurationService->locale();
        if (!in_array($this->ytLocale, $pumukitLocales)) {
            $this->ytLocale = $this->translator->getLocale();
        }
    }

    public function moveToList(MultimediaObject $multimediaObject, string $playlistTagId)
    {
        $youtube = $this->youtubeService->getYoutubeDocument($multimediaObject);

        if (null === $playlistTag = $this->documentManager->getRepository(Tag::class)->find($playlistTagId)) {
            $errorLog = __CLASS__.' ['.__FUNCTION__."] Error! The tag with id '".$playlistTagId."' for Youtube Playlist does not exist";
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }
        if (null === $playlistId = $playlistTag->getProperty('youtube')) {
            $errorLog = sprintf('%s [%s] Error! The tag with id %s doesn\'t have a \'youtube\' property!\n Did you use %s first?', __CLASS__, __FUNCTION__, $playlistTag->getId(), 'syncPlaylistsRelations()');
            $this->logger->error($errorLog);

            throw new \Exception();
        }

        $aResult = $this->youtubeProcessService->insertInToList($youtube, $playlistId, $youtube->getYoutubeAccount());
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

    public function updatePlaylists(MultimediaObject $multimediaObject)
    {
        $youtube = $this->youtubeService->getYoutubeDocument($multimediaObject);
        if (Youtube::STATUS_PUBLISHED !== $youtube->getStatus()) {
            return 0;
        }
        $this->checkAndAddDefaultPlaylistTag($multimediaObject);

        foreach ($multimediaObject->getTags() as $embedTag) {
            if (!$embedTag->isDescendantOfByCod($this->configurationService->metaTagPlaylistCod())) {
                // This is not the tag you are looking for
                continue;
            }
            $playlistTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $embedTag->getCod()]);

            if (!$playlistTag->getProperty('youtube_playlist')) {
                continue;
            }
            $playlistId = $playlistTag->getProperty('youtube');

            if (!isset($playlistId) || !array_key_exists($playlistId, $youtube->getPlaylists())) {
                // If the tag doesn't exist on youtube playlists
                $this->moveToList($multimediaObject, $playlistTag->getId());
            }
        }
        foreach ($youtube->getPlaylists() as $playlistId => $playlistRel) {
            $playlistTag = $this->getTagByYoutubeProperty($playlistId);
            // If the tag doesn't exist in PuMuKIT
            if (null === $playlistTag) {
                $errorLog = sprintf('%s [%s] Error! The tag with id %s => %s for Youtube Playlist does not exist', __CLASS__, __FUNCTION__, $playlistId, $playlistRel);
                $this->logger->warning($errorLog);

                continue;
            }
            if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                // If the mmobj doesn't have this tag
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

    public function syncPlaylistsRelations($dryRun = false)
    {
        if ($this->configurationService->useDefaultPlaylist()) {
            $this->getOrCreateDefaultTag();
        }
        $youtubeAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        foreach ($youtubeAccount->getChildren() as $account) {
            $allPlaylistTags = $account->getChildren();
            $login = $account->getProperty('login');

            // If these condition is deleted, syncPlaylistRelations brokes and all videos will be without playlist
            $currentDir = __DIR__.'/../Resources/data/accounts/';

            $secondaryPath = $this->kernelRootDir.'/../app/config/youtube_accounts/'.$login.'.json';
            if (!file_exists($currentDir.$login.'.json') && !file_exists($secondaryPath)) {
                $this->logger->error("There aren't file for account {$login}");

                continue;
            }

            $allYoutubePlaylists = $this->getAllYoutubePlaylists(
                $login
            ); // Returns array with all neccessary, list(['id','title'])
            // REFACTOR THIS ARRAY_MAP >>
            $allYoutubePlaylistsIds = array_map(
                function ($n) {
                    return $n['id'];
                },
                $allYoutubePlaylists
            );
            $master = $this->configurationService->playlistMaster();
            $allTagsYtId = [];

            foreach ($allPlaylistTags as $tag) {
                $ytPlaylistId = $tag->getProperty('youtube');
                $allTagsYtId[] = $ytPlaylistId;

                if (null === $ytPlaylistId || !in_array($ytPlaylistId, $allYoutubePlaylistsIds)) {
                    // If a playlist on PuMuKIT doesn't exist on Youtube, create it.
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
                    } elseif ($this->configurationService->deletePlaylist()) {
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
                            $this->createPumukitPlaylist($ytPlaylist, $account);
                        }
                    } elseif ($this->configurationService->deletePlaylist()) {
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
        $aResult = $this->youtubeProcessService->createPlaylist($youtubeTitlePlaylist, $this->configurationService->playlistPrivateStatus(), $tag->getParent()->getProperty('login'));
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
     * @param mixed $account
     *
     * @return Tag
     *
     * @throws \Exception
     */
    protected function createPumukitPlaylist($youtubePlaylist, $account)
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
        $tag->setParent($account);
        $this->documentManager->persist($tag);
        $this->documentManager->flush();

        return $tag;
    }

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

    // TODO Update Scripts:
    protected function updateYoutubePlaylist(Tag $tag)
    {
        echo 'update from Pumukit: '.$tag->getTitle($this->ytLocale)."\n";
    }

    protected function updatePumukitPlaylist(Tag $tag)
    {
        echo 'update from Youtube: '.$tag->getTitle($this->ytLocale)."\n";
    }

    protected function checkAndAddDefaultPlaylistTag(MultimediaObject $multimediaObject)
    {
        if (!$this->configurationService->useDefaultPlaylist()) {
            return 0;
        }
        $has_playlist = false;
        // This logic is duplicated here from getPlaylistsToUpdate in order to make this function more generic, and the criteria easier to change
        foreach ($multimediaObject->getTags() as $embedTag) {
            if ($embedTag->isDescendantOfByCod($this->configurationService->metaTagPlaylistCod())) {
                $has_playlist = true;

                break;
            }
        }
        if ($has_playlist) {
            return 0;
        }
        $playlistTag = $this->getOrCreateDefaultTag();
        // Adds the tag using the service.
        try {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $playlistTag->getId());
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }

        return 0;
    }

    protected function getOrCreateDefaultTag()
    {
        $playlistTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $this->configurationService->defaultPlaylistCod()]);
        if (isset($playlistTag)) {
            return $playlistTag;
        }
        $metatagPlaylist = $this->getPlaylistMetaTag();
        $playlistTag = new Tag();
        $playlistTag->setParent($metatagPlaylist);
        $playlistTag->setCod($this->configurationService->defaultPlaylistCod());
        $playlistTag->setTitle($this->configurationService->defaultPlaylistTitle());
        $playlistTag->setTitle($this->configurationService->defaultPlaylistTitle(), $this->ytLocale);
        $this->documentManager->persist($playlistTag);
        $this->documentManager->flush();

        return $playlistTag;
    }

    protected function getPlaylistMetaTag()
    {
        static $metatag = null;
        if (null !== $metatag) {
            return $metatag;
        }

        $metatag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $this->configurationService->metaTagPlaylistCod()]);
        if (!isset($metatag)) {
            $errorLog = sprintf('%s [%s] Error! The METATAG_PLAYLIST with cod:%s for YOUTUBE doesn\'t exist! \n Did you load the tag and set the correct cod in parameters.yml?', __CLASS__, __FUNCTION__, $this->configurationService->metaTagPlaylistCod());
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        return $metatag;
    }

    protected function getTagByYoutubeProperty($playlistId)
    {
        return $this->documentManager->createQueryBuilder(Tag::class)->field('properties.youtube')->equals($playlistId)->getQuery()->getSingleResult();
    }
}
