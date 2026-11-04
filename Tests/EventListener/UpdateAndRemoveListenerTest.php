<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\EventListener;

use PHPUnit\Framework\Attributes\Group;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;
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

    #[Group('to-change')]
    public function testWithTheDefaultConfigurationAnAudioForYoutubeFailsOnUpdate(): void
    {
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account], $this->track(null, ['master'], self::AUDIO_STREAMS));
        $multimediaObject->setType(MultimediaObject::TYPE_AUDIO);
        $this->dm->flush();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Profile youtube does not exist');

        $this->update($multimediaObject);
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

    private function update(MultimediaObject $multimediaObject): void
    {
        static::getContainer()->get(UpdateListener::class)->onMultimediaObjectUpdate(new MultimediaObjectEvent($multimediaObject));
    }
}
