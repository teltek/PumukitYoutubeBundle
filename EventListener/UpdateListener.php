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
    private $defaultTrackUpload;

    public function __construct(DocumentManager $documentManager, JobCreator $jobCreator, string $defaultTrackUpload)
    {
        $this->documentManager = $documentManager;
        $this->jobCreator = $jobCreator;
        $this->defaultTrackUpload = $defaultTrackUpload;
    }

    public function onMultimediaObjectUpdate(MultimediaObjectEvent $event): void
    {
        $multimediaObject = $event->getMultimediaObject();

        $this->updateYoutubeDocument($multimediaObject);
        $this->setYoutubeAccount($multimediaObject);

        $this->shouldGenerateJobForAudio($multimediaObject);
    }

    private function shouldGenerateJobForAudio(MultimediaObject $multimediaObject): void
    {
        $youtubeTag = $this->documentManager
            ->getRepository(Tag::class)
            ->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE])
        ;

        if (!$youtubeTag || !$multimediaObject->containsTag($youtubeTag)) {
            return;
        }

        if (!$multimediaObject->isOnlyAudio()) {
            return;
        }

        if ($multimediaObject->getTrackWithTag('profile:'.$this->defaultTrackUpload)) {
            return;
        }

        $track = $this->getTrackForYoutube($multimediaObject);
        if (!$track) {
            return;
        }

        $jobOptions = new JobOptions(
            $this->defaultTrackUpload,
            2,
            $track->language(),
            [],
            [],
            0,
            0,
            true
        );

        $path = Path::create($track->storage()->path()->path());
        $this->jobCreator->fromPath($multimediaObject, $path, $jobOptions);
    }

    private function getTrackForYoutube(MultimediaObject $multimediaObject)
    {
        $master = $multimediaObject->getTrackWithTag('master');
        if ($master) {
            return $master;
        }

        foreach ($multimediaObject->getTracksWithAnyTag(['display']) as $track) {
            if ($track->metadata()->isOnlyAudio()) {
                return $track;
            }
        }

        return null;
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
