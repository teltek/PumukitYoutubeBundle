<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use PHPUnit\Framework\Attributes\Group;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class VideoDeleteCommandTest extends YoutubeTestCase
{
    public function testAVideoThatShouldStayIsNotTouched(): void
    {
        $this->youtubeDocument($this->videoForYoutube());

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(0, $this->api->calls());
    }

    public function testAVideoNoLongerPublishedIsRemoved(): void
    {
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account], null, MultimediaObject::STATUS_BLOCKED);
        $this->youtubeDocument($multimediaObject);
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertRemoved($multimediaObject);
    }

    public function testAVideoWithoutTheChannelIsRemoved(): void
    {
        $multimediaObject = $this->video([$this->youtubeTag, $this->account]);
        $this->youtubeDocument($multimediaObject);
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertRemoved($multimediaObject);
    }

    public function testAVideoNoLongerPublicIsRemoved(): void
    {
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account], null, MultimediaObject::STATUS_PUBLISHED, EmbeddedBroadcast::TYPE_LOGIN);
        $this->youtubeDocument($multimediaObject);
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertRemoved($multimediaObject);
    }

    public function testItLeavesItsPlaylistsFirst(): void
    {
        $multimediaObject = $this->video([$this->youtubeTag, $this->account]);
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->setPlaylist('playlist', 'item');
        $this->dm->flush();
        $this->api->willAnswerEmpty();
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertStringContainsString('/playlistItems', (string) $this->api->request(0)->getUri());
        $this->assertStringContainsString('/videos', (string) $this->api->request(1)->getUri());
        $this->assertSame([], $this->youtubeOf($multimediaObject)->getPlaylists());
    }

    public function testAnOrphanDocumentIsRemoved(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $id = (string) $youtube->getId();
        $youtube->setMultimediaObjectId('aaaaaaaaaaaaaaaaaaaaaaaa');
        $this->dm->flush();
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(Youtube::STATUS_REMOVED, $this->reload(Youtube::class, $id)->getStatus());
    }

    public function testAChangeOfAccountPreparesTheNextUpload(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(Youtube::STATUS_UPLOADING, $this->youtubeOf($multimediaObject)->getStatus());
        $this->assertNull($this->reload(MultimediaObject::class, $multimediaObject->getId())->getProperty('youtube'));
        $this->assertSame((string) $youtube->getId(), (string) $this->youtubeOf($multimediaObject)->getId());
    }

    public function testAnErrorKeepsTheVideoAndNotesIt(): void
    {
        $multimediaObject = $this->video([$this->youtubeTag, $this->account]);
        $this->youtubeDocument($multimediaObject);
        $this->api->willFail('forbidden', 403);

        $this->runCommand('pumukit:youtube:video:delete');

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_PUBLISHED, $youtube->getStatus());
        $this->assertSame('forbidden', $youtube->getError()->id());
    }

    public function testTheQuotaStopsTheRun(): void
    {
        $this->youtubeDocument($this->video([$this->youtubeTag, $this->account]));
        $this->youtubeDocument($this->video([$this->youtubeTag, $this->account]));
        $this->api->willFail('quotaExceeded', 403);

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(1, $this->api->calls());
    }

    #[Group('to-change')]
    public function testAVideoAlreadyGoneFromYoutubeIsRetriedForever(): void
    {
        $multimediaObject = $this->video([$this->youtubeTag, $this->account]);
        $this->youtubeDocument($multimediaObject);
        $this->api->willFail('videoNotFound', 404);

        $this->runCommand('pumukit:youtube:video:delete');

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_PUBLISHED, $youtube->getStatus());
        $this->assertSame('videoNotFound', $youtube->getError()->id());
    }

    #[Group('to-change')]
    public function testAFailedDeleteOnAChangeOfAccountStillPreparesTheUpload(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $this->api->willFail('forbidden', 403);

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(Youtube::STATUS_UPLOADING, $this->youtubeOf($multimediaObject)->getStatus());
        $this->assertNull($this->reload(MultimediaObject::class, $multimediaObject->getId())->getProperty('youtube'));
    }

    #[Group('to-change')]
    public function testADocumentToDeleteWithoutVideoGetsStuck(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE, null);

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(Youtube::STATUS_TO_DELETE, $this->youtubeOf($multimediaObject)->getStatus());
        $this->assertSame(0, $this->api->calls());
    }

    private function assertRemoved(MultimediaObject $multimediaObject): void
    {
        $this->assertSame(1, $this->api->callsTo('DELETE', '/youtube/v3/videos'));
        $this->assertSame(Youtube::STATUS_REMOVED, $this->youtubeOf($multimediaObject)->getStatus());
        $stored = $this->reload(MultimediaObject::class, $multimediaObject->getId());
        $this->assertNull($stored->getProperty('youtube'));
        $this->assertNull($stored->getProperty('youtubeurl'));
    }
}
