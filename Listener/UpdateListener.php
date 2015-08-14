<?php

namespace Pumukit\YoutubeBundle\Listener;

use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\SchemaBundle\Document\MultimediaObject;

class UpdateListener
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $document = $args->getDocument();

        if ($document instanceof MultimediaObject) {
            $dm = $this->container->get("doctrine_mongodb.odm.document_manager");
            $youtubeRepo = $dm->getRepository("PumukitYoutubeBundle:Youtube");
            $youtube = $youtubeRepo->createQueryBuilder()
                ->field('multimediaObjectId')->equals($document->getId())
                ->getQuery()
                ->getSingleResult();

            if (null !== $youtube) {
                $youtube->setMultimediaObjectUpdateDate(new \DateTime('now'));
                $dm->persist($youtube);
                $dm->flush();
            }
        }
    }
}