<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class VideoStatusCommandTest extends YoutubeTestCase
{
    #[DataProvider('uploadStatuses')]
    public function testTheStatusOfYoutubeIsMapped(string $uploadStatus, int $expected): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PROCESSING);
        $this->api->willListAVideo('ytid', $uploadStatus);

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');

        $this->assertSame($expected, $this->youtubeOf($multimediaObject)->getStatus());
    }

    public static function uploadStatuses(): iterable
    {
        yield 'processed' => ['processed', Youtube::STATUS_PUBLISHED];

        yield 'uploaded' => ['uploaded', Youtube::STATUS_PROCESSING];

        yield 'deleted' => ['deleted', Youtube::STATUS_REMOVED];

        yield 'failed' => ['failed', Youtube::STATUS_ERROR];

        yield 'rejected' => ['rejected', Youtube::STATUS_ERROR];

        yield 'unknown' => ['something new', Youtube::STATUS_TO_REVIEW];
    }

    public function testARejectionKeepsItsReason(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PROCESSING);
        $this->api->willListAVideo('ytid', 'rejected', ['rejectionReason' => 'copyright']);

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');

        $error = $this->youtubeOf($multimediaObject)->getError();
        $this->assertSame('pumukit.statusError', $error->id());
        $this->assertSame('copyright', $error->message());
    }

    public function testAVideoMissingFromTheAnswerKeepsItsStatus(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PROCESSING);
        $this->api->willListNothing();

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_PROCESSING, $youtube->getStatus());
        $this->assertNull($youtube->getError());
    }

    public function testAnApiErrorKeepsTheStatusAndNotesTheError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PROCESSING);
        $this->api->willFail('backendError', 500);

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_PROCESSING, $youtube->getStatus());
        $this->assertSame('backendError', $youtube->getError()->id());
    }

    public function testTheQuotaStopsTheRun(): void
    {
        $this->youtubeDocument($this->videoForYoutube(), Youtube::STATUS_PROCESSING);
        $this->youtubeDocument($this->videoForYoutube(), Youtube::STATUS_PROCESSING);
        $this->api->willFail('quotaExceeded', 403);

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');

        $this->assertSame(1, $this->api->calls());
    }

    public function testAnUploadStillInTimeIsLeftAlone(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_UPLOADING, null, 3600);

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_UPLOADING, $youtube->getStatus());
        $this->assertSame(0, $this->api->calls());
    }

    public function testAnUploadOutOfTimeGoesToReview(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_UPLOADING, null, 4 * 3600);

        $this->runCommand('pumukit:youtube:video:update:pendingstatus');

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame(Youtube::STATUS_TO_REVIEW, $youtube->getStatus());
        $this->assertSame('pumukit.youtubeIdNotFound', $youtube->getError()->id());
    }

    public function testTheFullUpdateOnlyAsksForTheSettledOnes(): void
    {
        $published = $this->videoForYoutube();
        $this->youtubeDocument($published, Youtube::STATUS_PUBLISHED, 'published');
        $this->youtubeDocument($this->videoForYoutube(), Youtube::STATUS_PROCESSING, 'processing');
        $this->youtubeDocument($this->videoForYoutube(), Youtube::STATUS_REMOVED, 'removed');
        $this->youtubeDocument($this->videoForYoutube(), Youtube::STATUS_TO_REVIEW, 'review');
        $this->api->willListAVideo('published', 'deleted');

        $this->runCommand('pumukit:youtube:video:update:status');

        $this->assertSame(1, $this->api->calls());
        $this->assertSame(Youtube::STATUS_REMOVED, $this->youtubeOf($published)->getStatus());
    }

    public function testADocumentWithoutVideoEndsInError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $id = (string) $youtube->getId();
        $youtube->setMultimediaObjectId('aaaaaaaaaaaaaaaaaaaaaaaa');
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:video:update:status');

        $stored = $this->reload(Youtube::class, $id);
        $this->assertSame(Youtube::STATUS_ERROR, $stored->getStatus());
        $this->assertSame('pumukit.videoNotFound', $stored->getError()->id());
    }
}
