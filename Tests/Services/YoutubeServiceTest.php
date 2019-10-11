<?php

namespace Pumukit\YoutubeBundle\Tests\Services;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubePlaylistService;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 * @coversNothing
 */
class YoutubeServiceTest extends WebTestCase
{
    private $dm;
    private $youtubeRepo;
    private $mmobjRepo;
    private $tagRepo;
    private $logger;
    private $resourcesDir;
    private $playlistPrivacyStatus;
    private $multimediaobject_dispatcher;
    private $router;
    private $factoryService;
    private $notificationSender;
    private $translator;
    private $tagService;
    private $youtubeService;
    private $youtubePlaylistService;
    private $youtubeProcessService;

    public function setUp()
    {
        $options = ['environment' => 'test'];
        $kernel = static::createKernel($options);
        $kernel->boot();
        $this->dm = $kernel->getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->youtubeRepo = $this->dm
            ->getRepository(Youtube::class)
        ;
        $this->mmobjRepo = $this->dm
            ->getRepository(MultimediaObject::class)
        ;
        $this->tagRepo = $this->dm
            ->getRepository(Tag::class)
        ;
        $this->router = $kernel->getContainer()
            ->get('router')
        ;
        $this->logger = $kernel->getContainer()
            ->get('logger')
        ;
        $this->factoryService = $kernel->getContainer()
            ->get('pumukitschema.factory')
        ;
        $this->notificationSender = null;
        $this->translator = $kernel->getContainer()
            ->get('translator')
        ;
        $this->playlistPrivacyStatus = $kernel->getContainer()
            ->getParameter('pumukit_youtube.playlist_privacy_status')
        ;

        $this->dm->getDocumentCollection(MultimediaObject::class)->remove([]);
        $this->dm->getDocumentCollection(Series::class)->remove([]);
        $this->dm->getDocumentCollection(Tag::class)->remove([]);
        $this->dm->getDocumentCollection(Youtube::class)->remove([]);
        $this->dm->flush();
        $this->multimediaobject_dispatcher = $kernel->getContainer()
            ->get('pumukitschema.multimediaobject_dispatcher')
        ;
        $this->tagService = new TagService($this->dm, $this->multimediaobject_dispatcher);
        $this->youtubeProcessService = $kernel->getContainer()
            ->get('pumukityoutube.youtubeprocess')
        ;

        $kernelRootDir = $kernel->getContainer()->getParameter('kernel.root_dir');
        $locale = 'en';
        $useDefaultPlaylist = false;
        $defaultPlaylistCod = 'YOUTUBECONFERENCES';
        $defaultPlaylistTitle = 'Conferences';
        $metatagPlaylistCod = Youtube::YOUTUBE_TAG_CODE;
        $playlistMaster = ['pumukit', 'youtube'];
        $deletePlaylists = false;
        $pumukitLocales = ['en'];
        $youtubeSyncStatus = false;
        $defaultTrackUpload = 'master';
        $generateSbs = true;
        $sbsProfileName = 'sbs';
        $jobService = $this->getMockBuilder('Pumukit\EncoderBundle\Services\JobService')
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $jobService->expects($this->any())
            ->method('addJob')
            ->will($this->returnValue(0))
        ;
        $opencastService = null;
        $this->youtubeService = new YoutubeService($this->dm, $this->router, $this->tagService, $this->logger, $this->notificationSender, $this->translator, $this->youtubeProcessService, $this->playlistPrivacyStatus, $locale, $useDefaultPlaylist, $defaultPlaylistCod, $defaultPlaylistTitle, $metatagPlaylistCod, $playlistMaster, $deletePlaylists, $pumukitLocales, $youtubeSyncStatus, $defaultTrackUpload, $generateSbs, $sbsProfileName, $jobService, $opencastService);
        $this->youtubePlaylistService = new YoutubePlaylistService($this->dm, $this->youtubeService, $this->tagService, $this->logger, $this->translator, $this->youtubeProcessService, $this->playlistPrivacyStatus, $locale, $useDefaultPlaylist, $defaultPlaylistCod, $defaultPlaylistTitle, $metatagPlaylistCod, $playlistMaster, $deletePlaylists, $pumukitLocales, $defaultTrackUpload, $kernelRootDir);
        $this->resourcesDir = realpath(__DIR__.'/../Resources').'/';
    }

    public function tearDown()
    {
        $this->dm->close();
        $this->dm = null;
        $this->youtubeRepo = null;
        $this->tagRepo = null;
        $this->mmobjRepo = null;
        $this->tagService = null;
        $this->router = null;
        $this->logger = null;
        $this->factoryService = null;
        $this->notificationSender = null;
        $this->translator = null;
        $this->youtubeProcessService = null;
        $this->playlistPrivacyStatus = null;
        $this->multimediaobject_dispatcher = null;
        $this->youtubeService = null;
        $this->resourcesDir = null;
        gc_collect_cycles();
        parent::tearDown();
    }

    public function testYoutubeServiceFunctions()
    {
        $this->markTestSkipped('S');

        // Init tags
        $rootTag = $this->createTagWithCode('ROOT', 'ROOT', null, false, true);
        $this->dm->persist($rootTag);
        $this->dm->flush();

        $pubChannelTag = $this->createTagWithCode('PUBCHANNELS', 'PUBCHANNELS', 'ROOT', true, false);
        $youtubeTag = $this->createTagWithCode(Youtube::YOUTUBE_TAG_CODE, 'YouTube Playlists', 'ROOT', true, true);
        $this->dm->persist($pubChannelTag);
        $this->dm->persist($youtubeTag);
        $this->dm->flush();

        $youtubeEduTag = $this->createTagWithCode(Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE, 'YouTubeEDU', 'PUBCHANNELS', false, true);
        $playlistTag = $this->createTagWithCode('YOUTUBETEST', 'Test Playlist', Youtube::YOUTUBE_TAG_CODE, false, true);
        $this->dm->persist($youtubeEduTag);
        $this->dm->persist($playlistTag);
        $this->dm->flush();

        // Init Series with MultimediaObject
        $series = $this->factoryService->createSeries();
        $multimediaObject = $this->factoryService->createMultimediaObject($series);

        //$multimediaObject = $this->mmobjRepo->find($multimediaObject->getId());
        $addedTags = $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeEduTag->getId());
        $this->assertTrue($multimediaObject->containsTag($youtubeEduTag));

        // Create Track
        $track = new Track();
        $track->setPath($this->resourcesDir.'camera.mp4');
        $track->addTag('master');
        $track->setDuration(10);
        $this->dm->persist($track);

        $multimediaObject->addTrack($track);
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        $out = $this->youtubeService->upload($multimediaObject, 27, 'private', false);
        $this->assertEquals(0, $out);

        $playlistTag = $this->tagRepo->findOneBy(['cod' => 'YOUTUBETEST']);
        $out2 = $this->youtubePlaylistService->moveToList($multimediaObject, $playlistTag->getId());
        $this->assertEquals(0, $out2);

        $playlist2Tag = $this->createTagWithCode('YOUTUBETEST2', 'Test Playlist 2', Youtube::YOUTUBE_TAG_CODE, false, true);
        $this->dm->persist($playlist2Tag);
        $this->dm->flush();

        $out3 = $this->youtubeService->moveFromListToList($multimediaObject, $playlist2Tag->getId(), 27, 'private', false);
        $this->assertEquals(0, $out3);

        $out4 = $this->youtubeService->delete($multimediaObject);
        $this->assertEquals(0, $out4);
        $this->assertFalse($multimediaObject->containsTag($youtubeEduTag));

        $out5 = $this->youtubeService->upload($multimediaObject, 27, 'private', false);
        $this->assertEquals(0, $out5);

        $multimediaObject->setTitle('Test auth api');
        $multimediaObject->setDescription('Testing the google auth api to upload videos');
        $multimediaObject->setKeyword('testkeyword');
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        $out6 = $this->youtubeService->updateMetadata($multimediaObject);
        $this->assertEquals(0, $out6);

        $youtube = $this->youtubeRepo->findOneBy(['multimediaObjectId' => $multimediaObject->getId()]);
        $out7 = $this->youtubeService->updateStatus($youtube);
        $this->assertEquals(0, $out7);

        // Create Dual stream tracks
        $track1 = new Track();
        $track1->setPath($this->resourcesDir.'camera.mp4');
        $track1->addTag('presenter/delivery');
        $track1->setDuration(10);
        $this->dm->persist($track1);
        $track2 = new Track();
        $track2->setPath($this->resourcesDir.'camera.mp4');
        $track2->addTag('presentation/delivery');
        $track2->setDuration(10);
        $this->dm->persist($track2);
        $multimediaObject->addTrack($track1);
        $multimediaObject->addTrack($track2);
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        $out8 = $this->youtubeService->upload($multimediaObject, 27, 'private', false);
        $this->assertEquals(0, $out8);
    }

    private function createTagWithCode($code, $title, $tagParentCode = null, $metatag = false, $display = true)
    {
        if ($tag = $this->tagRepo->findOneBy(['cod' => $code])) {
            throw new \Exception('Nothing done - Tag retrieved from DB id: '.$tag->getId().' cod: '.$tag->getCod());
        }
        $tag = new Tag();
        $tag->setCod($code);
        $tag->setMetatag($metatag);
        $tag->setDisplay($display);
        $tag->setTitle($title, 'es');
        $tag->setTitle($title, 'gl');
        $tag->setTitle($title, 'en');
        if ($tagParentCode) {
            if ($parent = $this->tagRepo->findOneBy(['cod' => $tagParentCode])) {
                $tag->setParent($parent);
            } else {
                throw new \Exception('Nothing done - There is no tag in the database with code '.$tagParentCode.' to be the parent tag');
            }
        }
        $this->dm->persist($tag);
        $this->dm->flush();

        return $tag;
    }
}
