<?php

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\EmbeddedTag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;
use Pumukit\YoutubeBundle\Document\Youtube;

class UpdateListener
{
    /**
     * @var DocumentManager
     */
    private $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    /**
     * @throws \Exception
     */
    public function onMultimediaObjectUpdate(MultimediaObjectEvent $event): void
    {
        $multimediaObject = $event->getMultimediaObject();

        $this->updateYoutubeDocument($multimediaObject);

        $this->setYoutubeAccount($multimediaObject);
    }

    /**
     * @throws \Exception
     */
    private function updateYoutubeDocument(MultimediaObject $multimediaObject): void
    {
        $youtubeRepo = $this->documentManager->getRepository(Youtube::class);
        $youtube = $youtubeRepo->createQueryBuilder()
            ->field('multimediaObjectId')->equals($multimediaObject->getId())
            ->getQuery()
            ->getSingleResult()
        ;

        if ($youtube instanceof Youtube) {
            $youtube->setMultimediaObjectUpdateDate(new \DateTime());
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
        }
    }

    /**
     * Set YouTube account ( from template ) on multimedia object that was cut (TTK-22155).
     *
     * @throws \Exception
     */
    private function setYoutubeAccount(MultimediaObject $multimediaObject): void
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => Youtube::YOUTUBE_TAG_CODE,
        ]);

        if (!$youtubeTag) {
            throw new \Exception(Youtube::YOUTUBE_TAG_CODE.' tag not found');
        }

        if (!$multimediaObject->isPrototype() && !$multimediaObject->containsTag($youtubeTag)) {
            $prototype = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                'series' => new \MongoId($multimediaObject->getSeries()->getId()),
                'status' => MultimediaObject::STATUS_PROTOTYPE,
            ]);

            if (!$prototype) {
                throw new \Exception('Prototype for series '.$multimediaObject->getSeries().' not found');
            }

            foreach ($prototype->getTags() as $tag) {
                if ($tag->isDescendantOf($youtubeTag)) {
                    $this->updateTagsFromPrototype($tag, $multimediaObject);
                }
            }

            $this->documentManager->flush();
        }
    }

    private function updateTagsFromPrototype(EmbeddedTag $tag, MultimediaObject $multimediaObject): void
    {
        $multimediaObject->addTag($tag);
    }
}
