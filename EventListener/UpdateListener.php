<?php

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;

class UpdateListener
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function onMultimediaObjectUpdate(MultimediaObjectEvent $event)
    {
        $document = $event->getMultimediaObject();

        $dm = $this->container->get("doctrine_mongodb.odm.document_manager");
        $youtubeRepo = $dm->getRepository("PumukitYoutubeBundle:Youtube");
        $youtube = $youtubeRepo->createQueryBuilder()
            ->field('multimediaObjectId')->equals($document->getId())
            ->getQuery()
            ->getSingleResult();

        if (null != $youtube) {
            if ($youtube->getMultimediaObjectUpdateDate() < $youtube->getSyncMetadataDate()) {
                $youtube->setMultimediaObjectUpdateDate(new \DateTime('now'));
                $dm->persist($youtube);
                $dm->flush();
            }
        }
    }
}