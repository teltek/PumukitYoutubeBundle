<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
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

    public function testTheAccountListOpensItsFormsInTheModal(): void
    {
        $this->playlist('playlist1', 'list1');
        $this->dm->clear();
        $this->login();

        $crawler = $this->client->request('GET', self::BASE.'/');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#list_youtube_accounts[data-controller="admin--tag-tree"]'));
        $this->assertCount(1, $crawler->filter('a[data-bs-toggle="modal"][data-bs-target="#myModal"][href$="/admin/youtube/create"]'));
        $this->assertGreaterThan(0, $crawler->filter('#list_youtube_accounts a[data-bs-toggle="modal"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('#list_youtube_accounts [data-action="click->admin--tag-tree#toggle"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('#list_youtube_accounts [data-action="click->admin--tag-tree#remove"]')->count());
        $this->assertCount(0, $crawler->filter('[data-toggle], [data-dismiss], [onclick], #list_youtube_accounts script'));
    }

    #[DataProvider('formsOfTheModal')]
    public function testTheFormsWorkInsideTheModal(string $path, string $target): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $this->login();
        $url = strtr($path, ['{account}' => (string) $this->account->getId(), '{playlist}' => (string) $playlist->getId()]);

        $crawler = $this->client->request('GET', self::BASE.$url);

        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-controller="admin--modal-form"][data-action="submit->admin--modal-form#submit"]');
        $this->assertCount(1, $form);
        $this->assertSame(self::BASE.$url, $form->attr('action'));
        $this->assertSame([['target' => $target, 'url' => self::BASE.'/list']], json_decode((string) $form->attr('data-admin--modal-form-refresh-value'), true));
        $this->assertCount(1, $crawler->filter('.modal-header button[data-bs-dismiss="modal"]'));
        $this->assertCount(1, $crawler->filter('form button[type="submit"]'));
        $this->assertCount(0, $crawler->filter('script, [data-dismiss], form form'));
    }

    public static function formsOfTheModal(): iterable
    {
        yield 'new account' => ['/create', '#list_youtube_accounts'];

        yield 'edit account' => ['/edit/{account}', '#list_youtube_accounts'];

        yield 'new playlist' => ['/create/playlist/{account}', '#list_youtube_accounts'];

        yield 'edit playlist' => ['/edit/playlist/{playlist}', '#list_youtube_accounts'];
    }

    public function testAnAccountWithPlaylistsIsNotDeletedAndTheAnswerIsAnError(): void
    {
        $this->playlist('playlist1', 'list1');
        $this->dm->clear();
        $id = (string) $this->account->getId();
        $this->login();

        $this->client->request('DELETE', self::BASE.'/delete/'.$id);

        $this->assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->assertStringContainsString('can not be deleted', json_decode($this->client->getResponse()->getContent(), true)['status']);
        $this->dm->clear();
        $this->assertNotNull($this->dm->getRepository(Tag::class)->find($id));
    }

    public function testNoTemplateUsesTheOldFrontend(): void
    {
        $old = '/data-(toggle|dismiss|target|backdrop|placement)=|<script|\son[a-z]+=|\sstyle=|\bmdi-|glyphicon|\|\s*raw\b|col-xs-|text-(right|left)\b|btn-(default|raised)|\blabel-(danger|success|warning)|input-group-addon|class="well|twitter_bootstrap3|select2/';
        $found = [];
        $finder = (new Finder())->files()->in(dirname(__DIR__, 2).'/Resources/views')->name('*.twig');
        $this->assertGreaterThan(20, $finder->count());
        foreach ($finder as $file) {
            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match($old, $line)) {
                    $found[] = $file->getRelativePathname().':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function testTheControllersOfTheBundleAreRegistered(): void
    {
        $controllers = static::getContainer()->get(AssetMapperInterface::class)->getAsset('@symfony/stimulus-bundle/controllers.js')->content;

        $this->assertStringContainsString('youtube--publication', $controllers);
        $this->assertStringContainsString('youtube--video', $controllers);
    }

    public function testThePublicationPageListsThePlaylistsOfTheFirstAccountWithoutSelection(): void
    {
        $playlist = $this->playlist('playlist1', 'list1');
        $multimediaObject = $this->videoForYoutube();
        $multimediaObject->setStatus(MultimediaObject::STATUS_PROTOTYPE);
        foreach ($multimediaObject->getTags() as $tag) {
            if (in_array($tag->getLevel(), [3, 4], true) && 'PUCHYOUTUBE' !== $tag->getCod()) {
                $multimediaObject->removeTag($tag);
            }
        }
        $this->dm->flush();
        $this->dm->clear();
        $this->login();

        $crawler = $this->client->request('GET', self::BASE.'/update/config/'.$multimediaObject->getId());

        $this->assertResponseIsSuccessful();
        $panel = $crawler->filter('[data-controller="youtube--publication"]');
        $this->assertCount(1, $panel);
        $this->assertSame('pub_channels_PUCHYOUTUBE', $panel->attr('data-youtube--publication-channel-value'));
        $this->assertSame(self::BASE.'/playlist/list/'.$this->account->getId(), $crawler->filter('[data-youtube--publication-target="account"] option')->first()->attr('data-url'));
        $this->assertSame(['any', (string) $playlist->getId()], $crawler->filter('[data-youtube--publication-target="playlists"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        $this->assertSame('any', $crawler->filter('[data-youtube--publication-target="playlists"] option[selected]')->attr('value'));
        $this->assertSame(self::BASE.'/create/playlist/'.$this->account->getId(), $crawler->filter('a[data-youtube--publication-target="create"][data-bs-toggle="modal"]')->attr('href'));
    }

    public function testTheVideoModalOffersTheActionsOfAPublishedVideo(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PUBLISHED);
        $this->login();

        $crawler = $this->client->request('GET', self::BASE.'/modal/mm/'.$multimediaObject->getId());

        $this->assertResponseIsSuccessful();
        $actions = $crawler->filter('[data-controller="youtube--video"] [data-action="click->youtube--video#run"]');
        $this->assertSame(
            [self::BASE.'/updateplaylist/mm/'.$multimediaObject->getId(), self::BASE.'/forceuploads/mm/'.$multimediaObject->getId()],
            $actions->each(static fn (Crawler $action): string => (string) $action->attr('data-youtube--video-url-param'))
        );
        $this->assertNotEmpty($actions->last()->attr('data-youtube--video-confirm-param'));
        $this->assertSame(
            ['copy-youtube-link', 'copy-embed-link'],
            $crawler->filter('[data-action="click->admin--actions#copy"]')->each(static fn (Crawler $action): string => (string) $action->attr('data-admin--actions-input-param'))
        );
        $this->assertCount(2, $crawler->filter('input.pmk-display-none'));
        $this->assertCount(1, $crawler->filter('.modal-header button[data-bs-dismiss="modal"]'));
        $this->assertCount(1, $crawler->filter('.badge.text-bg-success'));
    }

    public function testTheVideoModalOfAVideoInProcessOffersTheCopyActionsButNotThePlaylist(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PROCESSING);
        $this->login();

        $crawler = $this->client->request('GET', self::BASE.'/modal/mm/'.$multimediaObject->getId());

        $this->assertCount(2, $crawler->filter('[data-action="click->admin--actions#copy"]'));
        $this->assertCount(1, $crawler->filter('[data-action="click->youtube--video#run"]'));
    }

    public function testTheVideoModalOfAVideoWithAnErrorOffersOnlyToForceTheUpload(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_ERROR);
        $this->login();

        $crawler = $this->client->request('GET', self::BASE.'/modal/mm/'.$multimediaObject->getId());

        $this->assertCount(0, $crawler->filter('[data-action="click->admin--actions#copy"]'));
        $this->assertCount(1, $crawler->filter('[data-action="click->youtube--video#run"]'));
        $this->assertCount(1, $crawler->filter('.badge.text-bg-danger'));
    }

    public function testTheConfigurationShowsAnEnabledOptionWithACheck(): void
    {
        $this->login();

        $crawler = $this->client->request('GET', self::BASE.'/stats/configuration/');

        $this->assertResponseIsSuccessful();
        $icons = [];
        $crawler->filter('tbody tr')->each(static function (Crawler $row) use (&$icons): void {
            if (2 < $row->filter('td')->count()) {
                $icons[trim($row->filter('td')->eq(1)->text())] = $row->filter('td i.fa-check')->count() ? 'check' : ($row->filter('td i.fa-xmark')->count() ? 'xmark' : 'value');
            }
        });
        $this->assertSame('xmark', $icons['syncStatus']);
        $this->assertSame('check', $icons['generateSbs']);
    }

    public function testTheStatsListEscapesTheDataOfTheDocument(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject, Youtube::STATUS_ERROR);
        $youtube->setFileUploaded('<img src=x onerror=alert(1)>');
        $youtube->setYoutubeId('');
        $youtube->setError(Error::create('invalidTitle', 'A bad title', new \DateTime(), []));
        $this->dm->flush();
        $this->login();

        $crawler = $this->client->request('GET', self::BASE.'/stats/list/error/upload');

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('<img src=x', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('<img src=x onerror=alert(1)>', $crawler->filter('td[headers="th-file"]')->text());
        $this->assertCount(1, $crawler->filter('td[headers="th-youtube-id"] i.fa-xmark'));
        $this->assertCount(1, $crawler->filter('td[headers="th-error"] a[data-bs-toggle="modal"][data-bs-target="#myModal"]'));
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
