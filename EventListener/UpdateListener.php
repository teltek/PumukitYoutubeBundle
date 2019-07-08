<?php

namespace Pumukit\YoutubeBundle\EventListener;

use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

        $dm = $this->container->get('doctrine_mongodb.odm.document_manager');
        $youtubeRepo = $dm->getRepository('PumukitYoutubeBundle:Youtube');
        $youtube = $youtubeRepo->createQueryBuilder()
            ->field('multimediaObjectId')->equals($document->getId())
            ->getQuery()
            ->getSingleResult()
        ;

        if (null != $youtube) {
            $youtube->setMultimediaObjectUpdateDate(new \DateTime('now'));
            $dm->persist($youtube);
            $dm->flush();
        }
    }
}
