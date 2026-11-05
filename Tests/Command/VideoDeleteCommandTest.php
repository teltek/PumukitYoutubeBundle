<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

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

    public function testAVideoAlreadyGoneFromYoutubeCountsAsRemoved(): void
    {
        $multimediaObject = $this->video([$this->youtubeTag, $this->account]);
        $this->youtubeDocument($multimediaObject);
        $this->api->willFail('videoNotFound', 404);

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertRemoved($multimediaObject);
        $this->assertNull($this->youtubeOf($multimediaObject)->getError());
    }

    public function testAFailedDeleteOnAChangeOfAccountKeepsItPendingWithTheError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $account = $youtube->getYoutubeAccount();
        $this->api->willFail('forbidden', 403);

        $this->runCommand('pumukit:youtube:video:delete');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_TO_DELETE, $stored->getStatus());
        $this->assertSame($account, $stored->getYoutubeAccount());
        $this->assertSame('forbidden', $stored->getError()->id());
        $this->assertNotNull($this->reload(MultimediaObject::class, $multimediaObject->getId())->getProperty('youtube'));
    }

    public function testAFailedDeleteOnAChangeOfAccountIsRetriedOnTheNextRun(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $this->api->willFail('backendError', 500);
        $this->runCommand('pumukit:youtube:video:delete');
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(2, $this->api->callsTo('DELETE', '/youtube/v3/videos'));
        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_UPLOADING, $stored->getStatus());
        $this->assertNull($stored->getError());
        $this->assertNull($this->reload(MultimediaObject::class, $multimediaObject->getId())->getProperty('youtube'));
    }

    public function testTheQuotaOnAChangeOfAccountKeepsItPending(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $this->api->willFail('quotaExceeded', 403);

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(Youtube::STATUS_TO_DELETE, $this->youtubeOf($multimediaObject)->getStatus());
        $this->assertNotNull($this->reload(MultimediaObject::class, $multimediaObject->getId())->getProperty('youtube'));
    }

    public function testAVideoAlreadyGoneOnAChangeOfAccountPreparesTheNextUpload(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $this->api->willFail('videoNotFound', 404);

        $this->runCommand('pumukit:youtube:video:delete');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_UPLOADING, $stored->getStatus());
        $this->assertNull($stored->getError());
        $this->assertNull($this->reload(MultimediaObject::class, $multimediaObject->getId())->getProperty('youtube'));
    }

    public function testAPlaylistEntryAlreadyGoneOnAChangeOfAccountStillDeletesTheVideo(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $youtube->setPlaylist('list1', 'item1');
        $this->dm->flush();
        $this->api->willFail('playlistItemNotFound', 404);
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(1, $this->api->callsTo('DELETE', '/youtube/v3/videos'));
        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_UPLOADING, $stored->getStatus());
        $this->assertSame([], $stored->getPlaylists());
    }

    public function testAPlaylistEntryThatCannotBeDeletedKeepsItPending(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $youtube->setPlaylist('list1', 'item1');
        $this->dm->flush();
        $this->api->willFail('forbidden', 403);

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(0, $this->api->callsTo('DELETE', '/youtube/v3/videos'));
        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_TO_DELETE, $stored->getStatus());
        $this->assertSame(['list1' => 'item1'], $stored->getPlaylists());
        $this->assertSame('forbidden', $stored->getError()->id());
    }

    public function testADocumentToDeleteWithoutVideoPreparesTheNextUpload(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE, null);

        $this->runCommand('pumukit:youtube:video:delete');

        $this->assertSame(Youtube::STATUS_UPLOADING, $this->youtubeOf($multimediaObject)->getStatus());
        $this->assertSame(0, $this->api->calls());
    }

    public function testADocumentToDeleteWithoutItsAccountStaysPendingWithTheError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject, Youtube::STATUS_TO_DELETE);
        $youtube->setYoutubeAccount('gone');
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:video:delete');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_TO_DELETE, $stored->getStatus());
        $this->assertSame('pumukit.accountNotFound', $stored->getError()->id());
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
