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
    const YOUTUBE_CODE = 'YOUTUBE';
    /**
     * @var DocumentManager
     */
    private $documentManager;

    /**
     * UpdateListener constructor.
     *
     * @param DocumentManager $documentManager
     */
    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    /**
     * @param MultimediaObjectEvent $event
     *
     * @throws \MongoException
     * @throws \Exception
     */
    public function onMultimediaObjectUpdate(MultimediaObjectEvent $event)
    {
        $multimediaObject = $event->getMultimediaObject();

        $this->updateYoutubeDocument($multimediaObject);

        $this->setYoutubeAccount($multimediaObject);
    }

    /**
     * @param MultimediaObject $multimediaObject
     *
     * @throws \Exception
     */
    private function updateYoutubeDocument(MultimediaObject $multimediaObject)
    {
        $youtubeRepo = $this->documentManager->getRepository(Youtube::class);
        $youtube = $youtubeRepo->createQueryBuilder()
            ->field('multimediaObjectId')->equals($multimediaObject->getId())
            ->getQuery()
            ->getSingleResult()
        ;

        if (null !== $youtube && $youtube instanceof Youtube) {
            $youtube->setMultimediaObjectUpdateDate(new \DateTime());
            $this->documentManager->persist($youtube);
            $this->documentManager->flush();
        }
    }

    /**
     * Set YouTube account ( from template ) on multimedia object that was cut (TTK-22155).
     *
     * @param MultimediaObject $multimediaObject
     *
     * @throws \MongoException
     */
    private function setYoutubeAccount(MultimediaObject $multimediaObject)
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => self::YOUTUBE_CODE,
        ]);

        if (!$youtubeTag) {
            throw new \Exception(self::YOUTUBE_CODE.' tag not found');
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

    /**
     * @param EmbeddedTag      $tag
     * @param MultimediaObject $multimediaObject
     */
    private function updateTagsFromPrototype(EmbeddedTag $tag, MultimediaObject $multimediaObject)
    {
        $multimediaObject->addTag($tag);
    }
}
