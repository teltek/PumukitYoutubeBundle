<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use PHPUnit\Framework\Attributes\Group;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class PlaylistCommandsTest extends YoutubeTestCase
{
    public function testAVideoIsAddedToItsPlaylist(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account, $playlist]);
        $this->youtubeDocument($multimediaObject);
        $this->api->willAnswer(['kind' => 'youtube#playlistItem', 'id' => 'item1']);

        $this->runCommand('pumukit:youtube:playlist:update');

        $this->assertSame(1, $this->api->callsTo('POST', '/playlistItems'));
        $this->assertSame(['list1' => 'item1'], $this->youtubeOf($multimediaObject)->getPlaylists());
    }

    public function testAVideoOutOfItsPlaylistLeavesIt(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->setPlaylist('list1', 'item1');
        $this->dm->flush();
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:playlist:update');

        $this->assertSame(1, $this->api->callsTo('DELETE', '/playlistItems'));
        $this->assertSame([], $this->youtubeOf($multimediaObject)->getPlaylists());
    }

    public function testAVideoAlreadyInPlaceCallsNothing(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account, $playlist]);
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->setPlaylist('list1', 'item1');
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:playlist:update');

        $this->assertSame(0, $this->api->calls());
    }

    public function testAFailedInsertIsNoted(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account, $playlist]);
        $this->youtubeDocument($multimediaObject);
        $this->api->willFail('playlistNotFound', 404);

        $this->runCommand('pumukit:youtube:playlist:update');

        $this->assertSame('playlistNotFound', $this->youtubeOf($multimediaObject)->getPlaylistUpdateError()->id());
    }

    #[Group('to-change')]
    public function testAFailedDeleteIsForgotten(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->setPlaylist('list1', 'item1');
        $this->dm->flush();
        $this->api->willFail('forbidden', 403);

        $this->runCommand('pumukit:youtube:playlist:update');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame([], $stored->getPlaylists());
        $this->assertNull($stored->getPlaylistUpdateError());
    }

    #[Group('to-change')]
    public function testTheQuotaDoesNotStopTheRun(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        foreach ([1, 2] as $ignored) {
            $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account, $playlist]);
            $this->youtubeDocument($multimediaObject);
            $this->api->willFail('quotaExceeded', 403);
        }

        $this->runCommand('pumukit:youtube:playlist:update');

        $this->assertSame(2, $this->api->calls());
    }

    #[Group('to-change')]
    public function testAnErrorWithoutJsonLosesWhatWasAlreadyDone(): void
    {
        $first = $this->playlist('playlist1', 'list1');
        $second = $this->playlist('playlist2', 'list2');
        $multimediaObject = $this->video([$this->publicationChannel, $this->youtubeTag, $this->account, $first, $second]);
        $this->youtubeDocument($multimediaObject);
        $this->api->willAnswer(['kind' => 'youtube#playlistItem', 'id' => 'item1']);
        $this->api->willFailWithoutJson();

        $tester = $this->runCommand('pumukit:youtube:playlist:update');

        $this->assertSame([], $this->youtubeOf($multimediaObject)->getPlaylists());
        $this->assertStringContainsString('update playlist of video', $tester->getDisplay());
    }

    public function testANewPlaylistIsCreatedOnYoutube(): void
    {
        $playlist = $this->tag('playlist1', $this->account);
        $id = (string) $playlist->getId();
        $this->api->willAnswer(['kind' => 'youtube#playlist', 'id' => 'list1']);

        $this->runCommand('pumukit:youtube:playlist:sync');

        $stored = $this->reload(Tag::class, $id);
        $this->assertSame('list1', $stored->getProperty('youtube'));
        $this->assertTrue($stored->getProperty('youtube_playlist'));
        $this->assertSame('public', json_decode((string) $this->api->lastRequest()->getBody(), true)['status']['privacyStatus']);
    }

    public function testAPlaylistRemovedFromYoutubeIsCreatedAgain(): void
    {
        $playlist = $this->playlist('playlist1', 'gone');
        $id = (string) $playlist->getId();
        $this->api->willAnswer(['items' => []]);
        $this->api->willAnswer(['kind' => 'youtube#playlist', 'id' => 'list2']);

        $this->runCommand('pumukit:youtube:playlist:sync');

        $this->assertSame('list2', $this->reload(Tag::class, $id)->getProperty('youtube'));
    }

    public function testAPlaylistInPlaceIsLeftAlone(): void
    {
        $this->playlist('playlist1', 'list1');
        $this->api->willAnswer(['items' => [['id' => 'list1']]]);

        $this->runCommand('pumukit:youtube:playlist:sync');

        $this->assertSame(1, $this->api->calls());
    }

    public function testATitleTooLongIsNotedAndNotSent(): void
    {
        $playlist = $this->tag('playlist1', $this->account);
        $playlist->setTitle(str_repeat('a', 151));
        $this->dm->flush();
        $id = (string) $playlist->getId();

        $this->runCommand('pumukit:youtube:playlist:sync');

        $this->assertSame(0, $this->api->calls());
        $this->assertStringContainsString('more characters', $this->reload(Tag::class, $id)->getProperty('youtube_error'));
    }
}
