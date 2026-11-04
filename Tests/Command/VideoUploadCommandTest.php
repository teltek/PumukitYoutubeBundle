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

    #[Group('to-change')]
    public function testAVideoInErrorIsUploadedAgainOnEveryRun(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_ERROR, null);
        $this->api->willFail('invalidTitle');
        $this->api->willFail('invalidTitle');

        $this->runCommand('pumukit:youtube:video:upload');
        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame(2, $this->api->callsTo('POST', '/upload/youtube/v3/videos'));
        $this->assertSame(0, $this->api->pending());
    }

    #[Group('to-change')]
    public function testARejectedVideoGoesBackToYoutubeLeavingACopy(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PROCESSING, 'rejected');
        $this->api->willListAVideo('rejected', 'rejected', ['rejectionReason' => 'duplicate']);
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('copy');

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');
        $this->runCommand('pumukit:youtube:video:upload');

        $this->assertSame('copy', $this->youtubeOf($multimediaObject)->getYoutubeId());
        $this->assertSame(0, $this->api->callsTo('DELETE', '/youtube/v3/videos'));
    }
}
