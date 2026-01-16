<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\EncoderBundle\Services\DTO\JobOptions;
use Pumukit\EncoderBundle\Services\JobCreator;
use Pumukit\SchemaBundle\Document\EmbeddedTag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;

class UpdateListener
{
    private $documentManager;
    private $jobCreator;

    public function __construct(DocumentManager $documentManager, JobCreator $jobCreator)
    {
        $this->documentManager = $documentManager;
        $this->jobCreator = $jobCreator;
    }

    public function onMultimediaObjectUpdate(MultimediaObjectEvent $event): void
    {
        $multimediaObject = $event->getMultimediaObject();

        $puchYoutube = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);
        if ($puchYoutube && !$multimediaObject->containsTag($puchYoutube)) {
            return;
        }

        $this->updateYoutubeDocument($multimediaObject);
        $this->setYoutubeAccount($multimediaObject);

        $master = $multimediaObject->getTrackWithTag('master');
        if (!$master || !$multimediaObject->isOnlyAudio()) {
            return;
        }

        if ($multimediaObject->getTrackWithTag('profile:video_youtube')) {
            return;
        }

        $jobOptions = new JobOptions(
            'video_youtube',
            2,
            $master->language(),
            [],
            [],
            0,
            0,
            true
        );

        $path = Path::create($master->storage()->path()->path());
        $this->jobCreator->fromPath($multimediaObject, $path, $jobOptions);
    }

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
     */
    private function setYoutubeAccount(MultimediaObject $multimediaObject): void
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        if (!$youtubeTag) {
            throw new \Exception(PumukitYoutubeBundle::YOUTUBE_TAG_CODE.' tag not found');
        }

        if (!$multimediaObject->isPrototype() && !$multimediaObject->containsTag($youtubeTag)) {
            $prototype = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                'series' => new ObjectId($multimediaObject->getSeries()->getId()),
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
