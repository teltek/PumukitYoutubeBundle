<?php

namespace Pumukit\YoutubeBundle\EventListener;

use Pumukit\NewAdminBundle\Event\PublicationSubmitEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BackofficeListener
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function onPublicationSubmit(PublicationSubmitEvent $event)
    {
        $dm = $this->container->get('doctrine_mongodb.odm.document_manager');
        $multimediaObject = $event->getMultimediaObject();
        $request = $event->getRequest();

        if ($request->request->has('youtube_label') and $request->request->has('youtube_playlist_label')) {
            $youtubeAccountTag = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
                array('_id' => new \MongoId($request->request->get('youtube_label')))
            );
            $youtubePlaylistTag = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
                array('_id' => new \MongoId($request->request->get('youtube_playlist_label')))
            );
            $multimediaObject->addTag($youtubeAccountTag);
            $multimediaObject->addTag($youtubePlaylistTag);
            $dm->flush();
        }
    }
}
