<?php

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class RemoveListener
{
    /**
     * @var DocumentManager
     */
    private $documentManager;

    /**
     * @var \Pumukit\YoutubeBundle\Repository\YoutubeRepository
     */
    private $youtubeRepo;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
        $this->youtubeRepo = $this->documentManager->getRepository(Youtube::class);
    }

    /**
     * @param LifecycleEventArgs $args
     *
     * @throws \Doctrine\ODM\MongoDB\LockException
     * @throws \Doctrine\ODM\MongoDB\Mapping\MappingException
     */
    public function preRemove(LifecycleEventArgs $args)
    {
        $document = $args->getDocument();

        if ($document instanceof MultimediaObject) {
            if (null !== $youtubeId = $document->getProperty('youtube')) {
                if (null != $youtube = $this->youtubeRepo->find($youtubeId)) {
                    $youtube->setStatus(Youtube::STATUS_TO_DELETE);
                    $this->documentManager->persist($youtube);
                    $this->documentManager->flush();
                }
            }
        }
    }
}
