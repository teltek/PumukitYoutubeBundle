<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class VideoMetadataCommandTest extends YoutubeTestCase
{
    public function testAChangedVideoIsSentToYoutube(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->changed($this->youtubeDocument($multimediaObject));
        $this->api->willAnswer(['kind' => 'youtube#video', 'id' => 'ytid']);
        $before = $youtube->getSyncMetadataDate();

        $this->runCommand('pumukit:youtube:video:update:metadata');

        $request = $this->api->lastRequest();
        $this->assertSame('PUT', $request->getMethod());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('ytid', $body['id']);
        $this->assertSame($multimediaObject->getTitle(), $body['snippet']['title']);
        $this->assertGreaterThan($before, $this->youtubeOf($multimediaObject)->getSyncMetadataDate());
    }

    public function testAVideoInSyncIsNotSent(): void
    {
        $youtube = $this->youtubeDocument($this->videoForYoutube());
        $youtube->setMultimediaObjectUpdateDate(new \DateTime('-2 days'));
        $youtube->setSyncMetadataDate(new \DateTime('-1 day'));
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:video:update:metadata');

        $this->assertSame(0, $this->api->calls());
    }

    public function testAVideoNotPublishedYetWaits(): void
    {
        $this->changed($this->youtubeDocument($this->videoForYoutube(), Youtube::STATUS_PROCESSING));

        $this->runCommand('pumukit:youtube:video:update:metadata');

        $this->assertSame(0, $this->api->calls());
    }

    public function testTheErrorIsNotedApartFromTheUploadOne(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->changed($this->youtubeDocument($multimediaObject));
        $this->api->willFail('invalidVideoMetadata');

        $this->runCommand('pumukit:youtube:video:update:metadata');

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame('invalidVideoMetadata', $youtube->getMetadataUpdateError()->id());
        $this->assertNull($youtube->getError());
        $this->assertSame(Youtube::STATUS_PUBLISHED, $youtube->getStatus());
    }

    public function testTheQuotaStopsTheRun(): void
    {
        $this->changed($this->youtubeDocument($this->videoForYoutube()));
        $this->changed($this->youtubeDocument($this->videoForYoutube()));
        $this->api->willFail('quotaExceeded', 403);

        $this->runCommand('pumukit:youtube:video:update:metadata');

        $this->assertSame(1, $this->api->calls());
    }

    public function testByDefaultAHiddenVideoStaysPublicOnYoutube(): void
    {
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account], null, MultimediaObject::STATUS_HIDDEN);
        $this->changed($this->youtubeDocument($multimediaObject));
        $this->api->willAnswer(['kind' => 'youtube#video', 'id' => 'ytid']);

        $this->runCommand('pumukit:youtube:video:update:metadata');

        $this->assertSame('public', json_decode((string) $this->api->lastRequest()->getBody(), true)['status']['privacyStatus']);
    }

    private function changed(Youtube $youtube): Youtube
    {
        $youtube->setSyncMetadataDate(new \DateTime('-2 days'));
        $youtube->setMultimediaObjectUpdateDate(new \DateTime('-1 day'));
        $this->dm->flush();

        return $youtube;
    }
}
