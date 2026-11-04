<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class RemoveListener
{
    public function preRemove(LifecycleEventArgs $args): void
    {
        $document = $args->getDocument();
        if (!$document instanceof MultimediaObject) {
            return;
        }

        $youtubeId = $document->getProperty('youtube');
        if (null === $youtubeId) {
            return;
        }

        $documentManager = $args->getDocumentManager();
        $youtube = $documentManager->getRepository(Youtube::class)->find($youtubeId);
        if (null === $youtube) {
            return;
        }

        $youtube->setStatus(Youtube::STATUS_TO_DELETE);
        $documentManager->persist($youtube);
        $documentManager->flush();
    }
}
