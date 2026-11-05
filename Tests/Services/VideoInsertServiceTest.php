<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Services;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Exception\YoutubeQuotaExceededException;
use Pumukit\YoutubeBundle\Services\VideoInsertService;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class VideoInsertServiceTest extends YoutubeTestCase
{
    public function testAnUploadLeavesTheDocumentProcessingWithItsLinks(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('newid');

        $this->assertTrue($this->service()->uploadVideoToYoutube($multimediaObject));

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_PROCESSING, $youtube->getStatus());
        $this->assertSame('newid', $youtube->getYoutubeId());
        $this->assertSame('https://www.youtube.com/watch?v=newid', $youtube->getLink());
        $this->assertStringContainsString('https://www.youtube.com/embed/newid', $youtube->getEmbed());
        $this->assertSame('channel', $youtube->getYoutubeAccount());
        $this->assertNull($youtube->getError());

        $stored = $this->reload(MultimediaObject::class, $multimediaObject->getId());
        $this->assertSame((string) $youtube->getId(), $stored->getProperty('youtube'));
        $this->assertSame('https://www.youtube.com/watch?v=newid', $stored->getProperty('youtubeurl'));
    }

    public function testTheVideoIsSentWithItsMetadata(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('newid');

        $this->service()->uploadVideoToYoutube($multimediaObject);

        $start = $this->api->request(0);
        $this->assertSame('POST', $start->getMethod());
        $this->assertStringContainsString('/upload/youtube/v3/videos', (string) $start->getUri());
        $body = json_decode((string) $start->getBody(), true);
        $this->assertSame($multimediaObject->getTitle(), $body['snippet']['title']);
        $this->assertSame('27', $body['snippet']['categoryId']);
        $this->assertSame('public', $body['status']['privacyStatus']);
        $this->assertStringContainsString('Description', $body['snippet']['description']);
    }

    public function testABigFileGoesInSeveralChunks(): void
    {
        $multimediaObject = $this->videoForYoutube($this->track($this->sparseFile(9 * 1024 * 1024)));
        $this->api->willStartAnUpload();
        $this->api->willKeepAnUpload(8 * 1024 * 1024 - 1);
        $this->api->willFinishAnUpload('newid');

        $this->assertTrue($this->service()->uploadVideoToYoutube($multimediaObject));

        $this->assertSame(3, $this->api->calls());
        $this->assertSame(Youtube::STATUS_PROCESSING, $this->youtubeOf($multimediaObject)->getStatus());
    }

    public function testAnApiErrorLeavesTheDocumentWithTheError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->api->willFail('invalidTitle');

        $this->assertFalse($this->service()->uploadVideoToYoutube($multimediaObject));

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_ERROR, $youtube->getStatus());
        $this->assertSame('invalidTitle', $youtube->getError()->id());
        $this->assertSame('invalidTitle message', $youtube->getError()->message());
    }

    public function testAnErrorWithoutJsonIsKeptAsAnApiError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->api->willFailWithoutJson();

        $this->assertFalse($this->service()->uploadVideoToYoutube($multimediaObject));

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_ERROR, $youtube->getStatus());
        $this->assertSame('pumukit.apiError', $youtube->getError()->id());
    }

    public function testQuotaRemovesTheDocumentAndStops(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->api->willFail('quotaExceeded', 403);

        try {
            $this->service()->uploadVideoToYoutube($multimediaObject);
            $this->fail('The quota exception should reach the caller');
        } catch (YoutubeQuotaExceededException $exception) {
            $this->assertSame('quotaExceeded', $exception->getReason());
        }

        $this->assertSame([], $this->youtubeDocuments());
        $stored = $this->reload(MultimediaObject::class, $multimediaObject->getId());
        $this->assertNull($stored->getProperty('youtube'));
    }

    public function testAFileOverTheLimitGoesToReviewWithoutCallingYoutube(): void
    {
        $multimediaObject = $this->videoForYoutube($this->track($this->sparseFile(1073741825)));

        $this->assertFalse($this->service()->uploadVideoToYoutube($multimediaObject));

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_TO_REVIEW, $youtube->getStatus());
        $this->assertSame('MAX_SIZE_UPLOAD', $youtube->getError()->id());
        $this->assertSame(0, $this->api->calls());
    }

    public function testWithoutAccountNothingIsCreated(): void
    {
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag]);

        $this->assertFalse($this->service()->uploadVideoToYoutube($multimediaObject));

        $this->assertSame([], $this->youtubeDocuments());
        $this->assertSame(0, $this->api->calls());
    }

    public function testAnAudioWithoutVideoTrackIsNotUploaded(): void
    {
        $multimediaObject = $this->videoForYoutube($this->track(null, ['master'], self::AUDIO_STREAMS));

        $this->assertFalse($this->service()->uploadVideoToYoutube($multimediaObject));

        $this->assertSame([], $this->youtubeDocuments());
    }

    public function testATrackTaggedForYoutubeWinsOverTheMaster(): void
    {
        $multimediaObject = $this->videoForYoutube($this->track(null, ['master']));
        $tagged = $this->track(null, ['profile:video_youtube']);
        $multimediaObject->addTrack($tagged);
        $this->dm->flush();
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('newid');

        $this->service()->uploadVideoToYoutube($multimediaObject);

        $this->assertSame(basename($tagged->storage()->path()->path()), $this->youtubeOf($multimediaObject)->getFileUploaded());
    }

    public function testAnAudioGoesUpWithItsVideoTrack(): void
    {
        $multimediaObject = $this->videoForYoutube($this->track(null, ['master'], self::AUDIO_STREAMS));
        $video = $this->track(null, ['youtube', 'html5', 'profile:video_youtube']);
        $multimediaObject->addTrack($video);
        $this->dm->flush();
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('newid');

        $this->assertTrue($this->service()->uploadVideoToYoutube($multimediaObject));

        $this->assertSame(basename($video->storage()->path()->path()), $this->youtubeOf($multimediaObject)->getFileUploaded());
    }

    public function testAM4vTrackIsNotUploaded(): void
    {
        $multimediaObject = $this->videoForYoutube($this->track($this->file('video', 'm4v')));

        $this->assertFalse($this->service()->uploadVideoToYoutube($multimediaObject));

        $this->assertSame(0, $this->api->calls());
    }

    public function testAnotherUploadDeletesThePreviousVideoFromYoutube(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_ERROR, 'rejected');
        $this->api->willAnswerEmpty();
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('second');

        $this->assertTrue($this->service()->uploadVideoToYoutube($multimediaObject));

        $documents = $this->youtubeDocuments();
        $this->assertCount(1, $documents);
        $this->assertSame('second', $documents[0]->getYoutubeId());
        $this->assertSame(1, $this->api->callsTo('DELETE', '/youtube/v3/videos'));
    }

    public function testAnotherUploadStopsIfThePreviousVideoCannotBeDeleted(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_ERROR, 'rejected');
        $this->api->willFail('forbidden', 403);

        $this->assertFalse($this->service()->uploadVideoToYoutube($multimediaObject));

        $documents = $this->youtubeDocuments();
        $this->assertCount(1, $documents);
        $this->assertSame('rejected', $documents[0]->getYoutubeId());
        $this->assertSame('forbidden', $documents[0]->getError()->id());
        $this->assertSame(0, $this->api->callsTo('POST', '/upload/youtube/v3/videos'));
    }

    public function testAnUploadAfterAChangeOfAccountDoesNotDeleteTheOldVideoAgain(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_UPLOADING, 'old');
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('second');

        $this->assertTrue($this->service()->uploadVideoToYoutube($multimediaObject));

        $this->assertSame(0, $this->api->callsTo('DELETE', '/youtube/v3/videos'));
        $this->assertSame('second', $this->youtubeOf($multimediaObject)->getYoutubeId());
    }

    private function service(): VideoInsertService
    {
        return static::getContainer()->get(VideoInsertService::class);
    }
}
