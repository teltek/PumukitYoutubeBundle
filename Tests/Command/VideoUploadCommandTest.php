<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

/**
 * @internal
 *
 * @coversNothing
 */
class VideoUploadCommandTest extends YoutubeTestCase
{
    public function testANewVideoIsUploaded(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('newid');

        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame('newid', $this->youtubeOf($multimediaObject)->getYoutubeId());
    }

    public function testWhatShouldNotGoToYoutubeIsSkipped(): void
    {
        $channels = [$this->publicationChannel, $this->youtubeTag, $this->account];
        $this->video([$this->youtubeTag, $this->account]);
        $this->video($channels, null, MultimediaObject::STATUS_BLOCKED);
        $this->video($channels, null, MultimediaObject::STATUS_PUBLISHED, EmbeddedBroadcast::TYPE_LOGIN);
        $fromYoutube = $this->video($channels);
        $fromYoutube->setProperty('origin', 'youtube');
        $fromPumukit1 = $this->video($channels);
        $fromPumukit1->setProperty('pumukit1id', '1');
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame(0, $this->api->calls());
    }

    public function testAPublishedVideoIsNotUploadedAgain(): void
    {
        $this->youtubeDocument($this->videoForYoutube());

        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame(0, $this->api->calls());
    }

    public function testAVideoInReviewIsNotUploadedAgain(): void
    {
        $this->youtubeDocument($this->videoForYoutube(), Youtube::STATUS_TO_REVIEW, null);

        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame(0, $this->api->calls());
    }

    public function testAFailureDoesNotStopTheNextVideo(): void
    {
        $failed = $this->videoForYoutube();
        $uploaded = $this->videoForYoutube();
        $this->api->willFail('invalidTitle');
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('newid');

        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame(Youtube::STATUS_ERROR, $this->youtubeOf($failed)->getStatus());
        $this->assertSame('newid', $this->youtubeOf($uploaded)->getYoutubeId());
    }

    public function testTheQuotaStopsTheRun(): void
    {
        $this->videoForYoutube();
        $this->videoForYoutube();
        $this->api->willFail('quotaExceeded', 403);

        $tester = $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame(1, $this->api->calls());
        $this->assertSame([], $this->youtubeDocuments());
        $this->assertStringContainsString('quota exceeded', $tester->getDisplay());
    }

    public function testAVideoWithAPermanentErrorIsNotUploadedAgain(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_ERROR, null);
        $this->api->willFail('invalidTitle');

        $this->runCommand('pumukit:youtube:video:upload');
        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame(1, $this->api->callsTo('POST', '/upload/youtube/v3/videos'));
        $this->assertSame('invalidTitle', $this->youtubeOf($multimediaObject)->getError()->id());
    }

    public function testAVideoWithATemporaryErrorIsRetried(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_ERROR, null);
        $this->api->willFail('backendError', 500);
        $this->runCommand('pumukit:youtube:video:upload');
        $this->assertSame('backendError', $this->youtubeOf($multimediaObject)->getError()->id());
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('uploaded');

        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame('uploaded', $this->youtubeOf($multimediaObject)->getYoutubeId());
    }

    public function testARejectedVideoIsNotUploadedAgain(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PROCESSING, 'rejected');
        $this->api->willListAVideo('rejected', 'rejected', ['rejectionReason' => 'duplicate']);

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');
        $this->runCommand('pumukit:youtube:video:upload');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_ERROR, $stored->getStatus());
        $this->assertSame('rejected', $stored->getYoutubeId());
        $this->assertSame(0, $this->api->callsTo('POST', '/upload/youtube/v3/videos'));
    }

    public function testTheUploadLimitOfTheChannelStopsTheRun(): void
    {
        $this->videoForYoutube();
        $this->videoForYoutube();
        $this->api->willFail('uploadLimitExceeded', 403);

        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame(1, $this->api->calls());
        $this->assertSame([], $this->youtubeDocuments());
    }

    public function testAnotherRunningUploadStopsThisOne(): void
    {
        $this->videoForYoutube();
        $lock = (new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore()))->createLock('pumukit:youtube:video:upload');
        $this->assertTrue($lock->acquire());

        $tester = $this->runCommand('pumukit:youtube:video:upload');

        $lock->release();
        $this->assertStringContainsString('Another upload is running', $tester->getDisplay());
        $this->assertSame(0, $this->api->calls());
    }
}
