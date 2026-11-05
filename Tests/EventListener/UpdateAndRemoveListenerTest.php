<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\EventListener;

use Pumukit\EncoderBundle\Document\Job;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\EventListener\UpdateListener;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class UpdateAndRemoveListenerTest extends YoutubeTestCase
{
    public function testUpdatingAVideoMarksItsDocumentAsChanged(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $before = $youtube->getMultimediaObjectUpdateDate();

        $this->update($multimediaObject);

        $this->assertGreaterThan($before, $this->youtubeOf($multimediaObject)->getMultimediaObjectUpdateDate());
    }

    public function testWithoutTheYoutubeTagAnUpdateDoesNotFail(): void
    {
        $multimediaObject = $this->video();
        $this->dm->remove($this->youtubeTag);
        $this->dm->flush();
        $this->dm->clear();

        $this->update($this->reload(MultimediaObject::class, $multimediaObject->getId()));

        $this->assertSame(0, $this->api->calls());
    }

    public function testACutVideoTakesTheAccountFromThePrototypeOfItsSeries(): void
    {
        $multimediaObject = $this->video();
        $prototype = new MultimediaObject();
        $prototype->setNumericalID(1000);
        $prototype->setStatus(MultimediaObject::STATUS_PROTOTYPE);
        $prototype->setType(MultimediaObject::TYPE_VIDEO);
        $prototype->setSeries($multimediaObject->getSeries());
        $prototype->addTag($this->account);
        $this->dm->persist($prototype);
        $this->dm->flush();

        $this->update($multimediaObject);

        $this->assertTrue($this->reload(MultimediaObject::class, $multimediaObject->getId())->containsTagWithCod('account_channel'));
    }

    public function testAnAudioForYoutubeGeneratesTheVideoTrackOnce(): void
    {
        $multimediaObject = $this->audioForYoutube();

        $this->update($multimediaObject);
        $this->update($multimediaObject);

        $jobs = $this->dm->getRepository(Job::class)->findBy(['mm_id' => $multimediaObject->getId()]);
        $this->assertCount(1, $jobs);
        $this->assertSame('video_youtube', $jobs[0]->getProfile());
    }

    public function testAVideoWithOnlyItsMasterCanGetTheChannel(): void
    {
        $multimediaObject = $this->video([], $this->track(null, ['master', 'profile:master_copy']));

        static::getContainer()->get(TagService::class)->addTagToMultimediaObject($multimediaObject, $this->publicationChannel->getId());

        $this->assertTrue($multimediaObject->containsTagWithCod('PUCHYOUTUBE'));
        $this->assertCount(0, $this->dm->getRepository(Job::class)->findBy(['mm_id' => $multimediaObject->getId()]));
    }

    public function testAnAudioWithItsVideoTrackDoesNotGenerateItAgain(): void
    {
        $multimediaObject = $this->audioForYoutube();
        $multimediaObject->addTrack($this->track(null, ['youtube', 'html5', 'profile:video_youtube']));
        $this->dm->flush();

        $this->update($multimediaObject);

        $this->assertSame([], $this->dm->getRepository(Job::class)->findBy(['mm_id' => $multimediaObject->getId()]));
    }

    public function testAVideoDoesNotGenerateTheTrackForAudios(): void
    {
        $multimediaObject = $this->videoForYoutube();

        $this->update($multimediaObject);

        $this->assertSame([], $this->dm->getRepository(Job::class)->findBy(['mm_id' => $multimediaObject->getId()]));
    }

    public function testRemovingAVideoMarksItsDocumentToDelete(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $id = (string) $youtube->getId();

        $this->dm->remove($multimediaObject);
        $this->dm->flush();

        $this->assertSame(Youtube::STATUS_TO_DELETE, $this->reload(Youtube::class, $id)->getStatus());
    }

    public function testRemovingAVideoWithoutDocumentDoesNothing(): void
    {
        $multimediaObject = $this->video();

        $this->dm->remove($multimediaObject);
        $this->dm->flush();

        $this->assertSame([], $this->youtubeDocuments());
    }

    private function audioForYoutube(): MultimediaObject
    {
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account], $this->track($this->audioFile(), ['master'], self::AUDIO_STREAMS));
        $multimediaObject->setType(MultimediaObject::TYPE_AUDIO);
        $this->dm->flush();

        return $multimediaObject;
    }

    private function update(MultimediaObject $multimediaObject): void
    {
        static::getContainer()->get(UpdateListener::class)->onMultimediaObjectUpdate(new MultimediaObjectEvent($multimediaObject));
    }
}
