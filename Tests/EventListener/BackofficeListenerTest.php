<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\EventListener;

use MongoDB\BSON\ObjectId;
use Pumukit\NewAdminBundle\Event\PublicationSubmitEvent;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\EventListener\BackofficeListener;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 *
 * @coversNothing
 */
class BackofficeListenerTest extends YoutubeTestCase
{
    public function testSavingAVideoForOtherChannelsWorks(): void
    {
        $multimediaObject = $this->video();

        $this->assertFalse($this->submit($multimediaObject, ['pub_channels' => ['PUCHWEBTV' => 'on']]));
    }

    public function testSavingWithoutChannelsWorks(): void
    {
        $this->assertFalse($this->submit($this->video(), []));
    }

    public function testChoosingYoutubeAddsTheAccountAndThePlaylists(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $multimediaObject = $this->video();

        $done = $this->submit($multimediaObject, [
            'pub_channels' => ['PUCHYOUTUBE' => 'on'],
            'youtube_label' => (string) $this->account->getId(),
            'youtube_playlist_label' => [(string) $playlist->getId(), 'any'],
        ]);

        $this->assertTrue($done);
        $stored = $this->reload(MultimediaObject::class, $multimediaObject->getId());
        $this->assertTrue($stored->containsTagWithCod('YOUTUBE'));
        $this->assertTrue($stored->containsTagWithCod('account_channel'));
        $this->assertTrue($stored->containsTagWithCod('playlist1'));
    }

    public function testChoosingYoutubeWithoutPlaylistWorks(): void
    {
        $multimediaObject = $this->video();

        $done = $this->submit($multimediaObject, [
            'pub_channels' => ['PUCHYOUTUBE' => 'on'],
            'youtube_label' => (string) $this->account->getId(),
        ]);

        $this->assertTrue($done);
        $this->assertTrue($this->reload(MultimediaObject::class, $multimediaObject->getId())->containsTagWithCod('account_channel'));
    }

    public function testRemovingYoutubeTakesItsTagsAway(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $multimediaObject = $this->video([$this->youtubeTag, $this->account, $playlist]);

        $this->submit($multimediaObject, ['pub_channels' => ['PUCHWEBTV' => 'on']]);

        $stored = $this->reload(MultimediaObject::class, $multimediaObject->getId());
        $this->assertFalse($stored->containsTagWithCod('account_channel'));
        $this->assertFalse($stored->containsTagWithCod('playlist1'));
    }

    public function testChangingTheAccountMarksTheVideoToDelete(): void
    {
        $other = $this->account('other');
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);

        $this->submit($multimediaObject, [
            'pub_channels' => ['PUCHYOUTUBE' => 'on'],
            'youtube_label' => (string) $other->getId(),
            'youtube_playlist_label' => ['any'],
        ]);

        $this->assertSame(Youtube::STATUS_TO_DELETE, $this->youtubeOf($multimediaObject)->getStatus());
    }

    public function testKeepingTheAccountLeavesTheVideo(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);

        $this->submit($multimediaObject, [
            'pub_channels' => ['PUCHYOUTUBE' => 'on'],
            'youtube_label' => (string) new ObjectId($this->account->getId()),
            'youtube_playlist_label' => ['any'],
        ]);

        $this->assertSame(Youtube::STATUS_PUBLISHED, $this->youtubeOf($multimediaObject)->getStatus());
    }

    private function submit(MultimediaObject $multimediaObject, array $data): bool
    {
        $event = new PublicationSubmitEvent($multimediaObject, new Request([], $data));

        return static::getContainer()->get(BackofficeListener::class)->onPublicationSubmit($event);
    }
}
