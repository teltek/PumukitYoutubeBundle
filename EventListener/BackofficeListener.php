<?php

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\NewAdminBundle\Event\PublicationSubmitEvent;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;

class BackofficeListener
{
    /**
     * @var DocumentManager
     */
    private $dm;

    /**
     * @var TagService
     */
    private $tagService;

    public function __construct(DocumentManager $documentManager, TagService $tagService)
    {
        $this->dm = $documentManager;
        $this->tagService = $tagService;
    }

    /**
     * @throws \Exception
     */
    public function onPublicationSubmit(PublicationSubmitEvent $event): bool
    {
        $request = $event->getRequest();
        $multimediaObject = $event->getMultimediaObject();
        $youtubeTag = $this->dm->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        if (!$youtubeTag) {
            return false;
        }

        foreach ($multimediaObject->getTags() as $embedTag) {
            if ($embedTag->isDescendantOf($youtubeTag)) {
                $this->tagService->removeTagFromMultimediaObject($multimediaObject, $embedTag->getId());
            }
        }

        if (!$request->request->has('pub_channels')) {
            return false;
        }

        $pubChannels = array_keys($request->request->get('pub_channels'));
        if (!in_array(Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE, $pubChannels, true)) {
            return false;
        }

        if (!$request->request->has('youtube_label') && !$request->request->has('youtube_playlist_label')) {
            return false;
        }

        // Add Youtube parent tag
        $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        // Add Youtube account
        $this->tagService->addTagToMultimediaObject($multimediaObject, new \MongoId($request->request->get('youtube_label')));
        // Add Youtube playlist
        $this->addPlaylistToMultimediaObject($multimediaObject, $request->request->get('youtube_playlist_label'));

        $youtubeDocument = $this->dm->getRepository(Youtube::class)->findOneBy(['multimediaObjectId' => $multimediaObject->getId()]);
        if ($youtubeDocument) {
            $accountLabel = $this->dm->getRepository(Tag::class)->findOneBy(['_id' => new \MongoId($request->request->get('youtube_label'))]);
            $differentAccount = $accountLabel && $youtubeDocument->getYoutubeAccount() !== $accountLabel->getProperty('login');
            if ($differentAccount) {
                $youtubeDocument->setStatus(Youtube::STATUS_TO_DELETE);
                $this->dm->flush();
            }
        }

        return true;
    }

    /**
     * @throws \Exception
     */
    private function addPlaylistToMultimediaObject(MultimediaObject $multimediaObject, array $multiplePlaylist): void
    {
        foreach ($multiplePlaylist as $playlist) {
            if ('any' !== $playlist) {
                $this->tagService->addTagToMultimediaObject(
                    $multimediaObject,
                    new \MongoId($playlist)
                );
            }
        }
    }
}
