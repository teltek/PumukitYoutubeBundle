<?php

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\NewAdminBundle\Event\PublicationSubmitEvent;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;

class BackofficeListener
{
    private $dm;
    private $tagService;

    public function __construct(DocumentManager $documentManager, TagService $tagService)
    {
        $this->dm = $documentManager;
        $this->tagService = $tagService;
    }

    /**
     * @param PublicationSubmitEvent $event
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function onPublicationSubmit(PublicationSubmitEvent $event)
    {
        $request = $event->getRequest();
        $multimediaObject = $event->getMultimediaObject();
        $youtubeTag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => 'YOUTUBE'));
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
        if (!in_array('PUCHYOUTUBE', $pubChannels)) {
            return false;
        }

        if ($request->request->has('youtube_label') and $request->request->has('youtube_playlist_label')) {
            // Youtube parent tag
            $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
            // Youtube account
            $this->tagService->addTagToMultimediaObject($multimediaObject, new \MongoId($request->request->get('youtube_label')));
            // Youtube playlist
            if ('any' !== $request->request->get('youtube_playlist_label')) {
                $this->tagService->addTagToMultimediaObject(
                    $multimediaObject,
                    new \MongoId($request->request->get('youtube_playlist_label'))
                );
            }

            $youtubeDocument = $this->dm->getRepository('PumukitYoutubeBundle:Youtube')->findOneBy(array('multimediaObjectId' => $multimediaObject->getId()));
            if ($youtubeDocument) {
                $accountLabel = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('_id' => new \MongoId($request->request->get('youtube_label'))));
                $differentAccount = $accountLabel && $youtubeDocument->getYoutubeAccount() !== $accountLabel->getProperty('login');
                if ($differentAccount) {
                    $youtubeDocument->setStatus(Youtube::STATUS_TO_DELETE);
                    $this->dm->flush();
                }
            }
        }
    }
}
