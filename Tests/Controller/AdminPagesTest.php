<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 *
 * @coversNothing
 */
class AdminPagesTest extends YoutubeTestCase
{
    private const BASE = '/admin/youtube';

    #[DataProvider('pagesOfTheRole')]
    public function testThePageNeedsTheRoleAndThenShows(string $path): void
    {
        $this->client->loginUser($this->user(['ROLE_SCOPE_GLOBAL'], 'nobody'), 'pumukit');
        $this->client->request('GET', self::BASE.$path);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->login();
        $this->client->request('GET', self::BASE.$path);
        $this->assertResponseIsSuccessful();
    }

    public static function pagesOfTheRole(): iterable
    {
        yield 'accounts' => ['/'];

        yield 'account list' => ['/list'];

        yield 'new account' => ['/create'];

        yield 'stats' => ['/stats/'];

        yield 'list by status' => ['/stats/status/list/3'];

        yield 'list by account' => ['/stats/account/list/channel'];

        yield 'upload errors' => ['/stats/list/error/upload'];

        yield 'metadata errors' => ['/stats/list/error/metadata'];

        yield 'playlist errors' => ['/stats/list/error/playlists'];

        yield 'caption errors' => ['/stats/list/error/caption'];

        yield 'configuration' => ['/stats/configuration/'];
    }

    public function testThePagesShowADocumentWithAnError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject, Youtube::STATUS_ERROR);
        $youtube->setError(Error::create('invalidTitle', 'A bad title', new \DateTime(), []));
        $this->dm->flush();
        $this->login();

        $this->client->request('GET', self::BASE.'/stats/list/error/upload');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString((string) $multimediaObject->getId(), $this->client->getResponse()->getContent());

        $this->client->request('GET', self::BASE.'/stats/modal/error/'.$youtube->getId());
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('invalidTitle', $this->client->getResponse()->getContent());
    }

    public function testAnAccountIsCreated(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', self::BASE.'/create');
        $form = $crawler->filter('form')->form();
        $form['pumukit_youtube_account[login]'] = 'second';
        foreach (array_keys($form->getValues()) as $field) {
            if (str_contains($field, 'i18n_title')) {
                $form[$field] = 'Second';
            }
        }

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSame(['success'], json_decode($this->client->getResponse()->getContent(), true));
        $this->dm->clear();
        $account = $this->dm->getRepository(Tag::class)->findOneBy(['properties.login' => 'second']);
        $this->assertSame('YOUTUBE', $account->getParent()->getCod());
    }

    public function testAnAccountCanBeEditedAndGetsItsPlaylists(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $this->login();

        $this->client->request('GET', self::BASE.'/edit/'.$this->account->getId());
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', self::BASE.'/children/'.$this->account->getId());
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', self::BASE.'/create/playlist/'.$this->account->getId());
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', self::BASE.'/edit/playlist/'.$playlist->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testThePlaylistSelectorAnswersWithTheOptionWithoutPlaylist(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $this->login();

        $this->client->request('GET', self::BASE.'/playlist/list/'.$this->account->getId());

        $options = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('any', $options[0]['id']);
        $this->assertSame((string) $playlist->getId(), $options[1]['id']);
    }

    public function testThePublicationPageGetsTheConfigurationOfTheAccount(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->login();

        $this->client->request('GET', self::BASE.'/update/config/'.$multimediaObject->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('youtube_label', $this->client->getResponse()->getContent());
    }

    public function testTheVideoModalShowsTheDocument(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->login();

        $this->client->request('GET', self::BASE.'/modal/mm/'.$multimediaObject->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('https://www.youtube.com/watch?v=ytid', $this->client->getResponse()->getContent());
    }

    public function testTheVideoModalWithoutDocumentSaysSo(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->login();

        $this->client->request('GET', self::BASE.'/modal/mm/'.$multimediaObject->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testThePlaylistOfAVideoCanBeUpdatedByHand(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->login();

        $this->client->request('GET', self::BASE.'/updateplaylist/mm/'.$multimediaObject->getId());

        $this->assertSame('true', $this->client->getResponse()->getContent());
    }

    public function testAForcedUploadDeletesThePreviousVideoAndUploads(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->login();
        $this->api->willAnswerEmpty();
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('newid');

        $this->client->request('GET', self::BASE.'/forceuploads/mm/'.$multimediaObject->getId());

        $this->assertSame('true', $this->client->getResponse()->getContent());
        $this->assertSame(1, $this->api->callsTo('DELETE', '/youtube/v3/videos'));
        $this->assertSame('newid', $this->youtubeOf($multimediaObject)->getYoutubeId());
    }

    public function testAForcedUploadStopsIfThePreviousVideoCannotBeDeleted(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->login();
        $this->api->willFail('forbidden', 403);

        $this->client->request('GET', self::BASE.'/forceuploads/mm/'.$multimediaObject->getId());

        $this->assertStringContainsString('Cannot remove', $this->client->getResponse()->getContent());
        $this->assertSame('ytid', $this->youtubeOf($multimediaObject)->getYoutubeId());
    }

    public function testAForcedUploadOfAFileOverTheLimitChangesNothing(): void
    {
        $multimediaObject = $this->videoForYoutube($this->track($this->sparseFile(1073741825)));
        $this->youtubeDocument($multimediaObject);
        $this->login();

        $this->client->request('GET', self::BASE.'/forceuploads/mm/'.$multimediaObject->getId());

        $this->assertStringContainsString('maximum allowed', $this->client->getResponse()->getContent());
        $this->assertSame(0, $this->api->calls());
    }

    #[Group('to-change')]
    public function testAnyScopeCanForceAnUploadWithoutTheRole(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->client->loginUser($this->user(['ROLE_SCOPE_PERSONAL'], 'personal'), 'pumukit');
        $this->api->willAnswerEmpty();
        $this->api->willStartAnUpload();
        $this->api->willFinishAnUpload('newid');

        $this->client->request('GET', self::BASE.'/forceuploads/mm/'.$multimediaObject->getId());

        $this->assertSame('newid', $this->youtubeOf($multimediaObject)->getYoutubeId());
    }

    #[Group('to-change')]
    public function testAGetDeletesAnyTag(): void
    {
        $tag = $this->tag('unrelated');
        $id = (string) $tag->getId();
        $this->login();

        $this->client->request('GET', self::BASE.'/delete/'.$id);

        $this->dm->clear();
        $this->assertNull($this->dm->getRepository(Tag::class)->find($id));
    }

    private function login(): void
    {
        $this->client->loginUser($this->user(['ROLE_SCOPE_GLOBAL', 'ROLE_ACCESS_YOUTUBE'], 'admin'.uniqid()), 'pumukit');
    }
}
