<?php

namespace Pumukit\YoutubeBundle\Tests\Services;

use Pumukit\SchemaBundle\Document\Material;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\CaptionService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 * @coversNothing
 */
class CaptionServiceTest extends WebTestCase
{
    private $dm;
    private $youtubeRepo;
    private $mmobjRepo;
    private $tagRepo;
    private $logger;
    private $resourcesDir;
    private $playlistPrivacyStatus;
    private $multimediaobject_dispatcher;
    private $factoryService;
    private $notificationSender;
    private $translator;
    private $tagService;
    private $youtubeProcessService;
    private $captionService;
    private $router;

    public function setUp()
    {
        $options = ['environment' => 'test'];
        $kernel = static::createKernel($options);
        $kernel->boot();
        $this->dm = $kernel->getContainer()
            ->get('doctrine_mongodb.odm.document_manager')
        ;
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
        $this->youtubeProcessService = $this->getMockBuilder('Pumukit\YoutubeBundle\Services\YoutubeProcessService')
            ->disableOriginalConstructor()
            ->getMock()
        ;
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
        $this->captionService = new CaptionService($this->dm, $this->router, $this->tagService, $this->logger, $this->notificationSender, $this->translator, $this->youtubeProcessService, $this->playlistPrivacyStatus, $locale, $useDefaultPlaylist, $defaultPlaylistCod, $defaultPlaylistTitle, $metatagPlaylistCod, $playlistMaster, $deletePlaylists, $pumukitLocales, $youtubeSyncStatus, $defaultTrackUpload, $generateSbs, $sbsProfileName, $jobService, $opencastService);
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
        $this->captionService = null;
        $this->resourcesDir = null;
        gc_collect_cycles();
        parent::tearDown();
    }

    public function testListAllCaptions()
    {
        $multimediaObject = $this->initMultimediaObject();
        $youtube = $this->initYoutube($multimediaObject);

        $listOutput = [
            'out' => [
                'Youtube-Caption-Id-1' => [
                    'name' => 'Subtitle in English',
                    'language' => 'en',
                    'is_draft' => false,
                    'last_updated' => '2018-01-31 09:00:00',
                ],
                'Youtube-Caption-Id-2' => [
                    'name' => 'Subtitle in Spanish',
                    'language' => 'es',
                    'is_draft' => false,
                    'last_updated' => '2018-01-31 09:01:00',
                ],
                'Youtube-Caption-Id-3' => [
                    'name' => 'Subtitle in French',
                    'language' => 'fr',
                    'is_draft' => false,
                    'last_updated' => '2018-01-31 09:02:00',
                ],
            ],
            'error' => false,
        ];
        $this->youtubeProcessService->expects($this->any())
            ->method('listCaptions')
            ->will($this->returnValue($listOutput))
        ;

        $out = $this->captionService->listAllCaptions($multimediaObject);
        $this->assertEquals($listOutput['out'], $out);
    }

    public function testUploadCaption()
    {
        $multimediaObject = $this->initMultimediaObject();
        $youtube = $this->initYoutube($multimediaObject);

        // Material 1
        $name1 = 'Caption in English';
        $language1 = 'en';
        $mimeType1 = 'vtt';
        $filename1 = 'caption_en.vtt';

        // Material 2
        $name2 = 'Caption in Spanish';
        $language2 = 'es';
        $mimeType2 = 'srt';
        $filename2 = 'caption_es.srt';

        // Material 3
        $name3 = 'Caption in French';
        $language3 = 'fr';
        $mimeType3 = 'dfxp';
        $filename3 = 'caption_fr.dfxp';

        // Caption 1
        $captionId1 = 'Youtube-Caption-Id-1';
        $isDraft1 = false;
        $lastUpdatedString1 = '2018-01-31 09:00:00';
        $lastUpdated1 = new \DateTime($lastUpdatedString1);

        // Caption 2
        $captionId2 = 'Youtube-Caption-Id-2';
        $isDraft2 = true;
        $lastUpdatedString2 = '2018-01-31 09:02:00';
        $lastUpdated2 = new \DateTime($lastUpdatedString2);

        // Caption 3
        $captionId3 = 'Youtube-Caption-Id-3';
        $isDraft3 = false;
        $lastUpdatedString3 = '2018-01-31 09:03:00';
        $lastUpdated3 = new \DateTime($lastUpdatedString3);

        $material1 = $this->initMaterial($multimediaObject, $name1, $language1, $mimeType1, $filename1);
        $material2 = $this->initMaterial($multimediaObject, $name2, $language2, $mimeType2, $filename2);
        $material3 = $this->initMaterial($multimediaObject, $name3, $language3, $mimeType3, $filename3);

        $insertOutput1 = $this->initInsertOutput($captionId1, $name1, $language1, $isDraft1, $lastUpdatedString1);
        $insertOutput2 = $this->initInsertOutput($captionId2, $name2, $language2, $isDraft2, $lastUpdatedString2);
        $insertOutput3 = $this->initInsertOutput($captionId3, $name3, $language3, $isDraft3, $lastUpdatedString3);

        $this->youtubeProcessService->expects($this->at(0))
            ->method('insertCaption')
            ->will($this->returnValue($insertOutput1))
        ;
        $this->youtubeProcessService->expects($this->at(1))
            ->method('insertCaption')
            ->will($this->returnValue($insertOutput2))
        ;
        $this->youtubeProcessService->expects($this->at(2))
            ->method('insertCaption')
            ->will($this->returnValue($insertOutput3))
        ;

        $materialIds1 = [];
        $materialIds1[] = $material1->getId();
        $out1 = $this->captionService->uploadCaption($multimediaObject, $materialIds1);
        $this->assertEquals($insertOutput1['out'], $out1[0]);

        $caption1 = $youtube->getCaptionByMaterialId($material1->getId());
        $this->assertEquals($name1, $caption1->getName());
        $this->assertEquals($language1, $caption1->getLanguage());
        $this->assertEquals($isDraft1, $caption1->getIsDraft());
        $this->assertEquals($lastUpdated1, $caption1->getLastUpdated());
        $this->assertEquals($material1->getId(), $caption1->getMaterialId());

        $this->assertNull($youtube->getCaptionByMaterialId($material2->getId()));
        $this->assertNull($youtube->getCaptionByMaterialId($material3->getId()));

        $materialIds2 = [];
        $materialIds2[] = $material2->getId();
        $materialIds2[] = $material3->getId();

        $out2 = $this->captionService->uploadCaption($multimediaObject, $materialIds2);
        $this->assertEquals($insertOutput2['out'], $out2[0]);
        $this->assertEquals($insertOutput3['out'], $out2[1]);

        $caption2 = $youtube->getCaptionByMaterialId($material2->getId());
        $this->assertEquals($name2, $caption2->getName());
        $this->assertEquals($language2, $caption2->getLanguage());
        $this->assertEquals($isDraft2, $caption2->getIsDraft());
        $this->assertEquals($lastUpdated2, $caption2->getLastUpdated());
        $this->assertEquals($material2->getId(), $caption2->getMaterialId());

        $caption3 = $youtube->getCaptionByMaterialId($material3->getId());
        $this->assertEquals($name3, $caption3->getName());
        $this->assertEquals($language3, $caption3->getLanguage());
        $this->assertEquals($isDraft3, $caption3->getIsDraft());
        $this->assertEquals($lastUpdated3, $caption3->getLastUpdated());
        $this->assertEquals($material3->getId(), $caption3->getMaterialId());
    }

    public function testDeleteCaption()
    {
        $multimediaObject = $this->initMultimediaObject();
        $youtube = $this->initYoutube($multimediaObject);

        // Material 1
        $name1 = 'Caption in English';
        $language1 = 'en';
        $mimeType1 = 'vtt';
        $filename1 = 'caption_en.vtt';

        // Material 2
        $name2 = 'Caption in Spanish';
        $language2 = 'es';
        $mimeType2 = 'srt';
        $filename2 = 'caption_es.srt';

        // Material 3
        $name3 = 'Caption in French';
        $language3 = 'fr';
        $mimeType3 = 'dfxp';
        $filename3 = 'caption_fr.dfxp';

        // Caption 1
        $captionId1 = 'Youtube-Caption-Id-1';
        $isDraft1 = false;
        $lastUpdatedString1 = '2018-01-31 09:00:00';
        $lastUpdated1 = new \DateTime($lastUpdatedString1);

        // Caption 2
        $captionId2 = 'Youtube-Caption-Id-2';
        $isDraft2 = true;
        $lastUpdatedString2 = '2018-01-31 09:02:00';
        $lastUpdated2 = new \DateTime($lastUpdatedString2);

        // Caption 3
        $captionId3 = 'Youtube-Caption-Id-3';
        $isDraft3 = false;
        $lastUpdatedString3 = '2018-01-31 09:03:00';
        $lastUpdated3 = new \DateTime($lastUpdatedString3);

        $material1 = $this->initMaterial($multimediaObject, $name1, $language1, $mimeType1, $filename1);
        $material2 = $this->initMaterial($multimediaObject, $name2, $language2, $mimeType2, $filename2);
        $material3 = $this->initMaterial($multimediaObject, $name3, $language3, $mimeType3, $filename3);

        $insertOutput1 = $this->initInsertOutput($captionId1, $name1, $language1, $isDraft1, $lastUpdatedString1);
        $insertOutput2 = $this->initInsertOutput($captionId2, $name2, $language2, $isDraft2, $lastUpdatedString2);
        $insertOutput3 = $this->initInsertOutput($captionId3, $name3, $language3, $isDraft3, $lastUpdatedString3);

        $this->youtubeProcessService->expects($this->at(0))
            ->method('insertCaption')
            ->will($this->returnValue($insertOutput1))
        ;
        $this->youtubeProcessService->expects($this->at(1))
            ->method('insertCaption')
            ->will($this->returnValue($insertOutput2))
        ;
        $this->youtubeProcessService->expects($this->at(2))
            ->method('insertCaption')
            ->will($this->returnValue($insertOutput3))
        ;

        $materialIds = [];
        $materialIds[] = $material1->getId();
        $materialIds[] = $material2->getId();
        $materialIds[] = $material3->getId();
        $out1 = $this->captionService->uploadCaption($multimediaObject, $materialIds);

        $this->assertNotNull($youtube->getCaptionByMaterialId($material1->getId()));
        $this->assertNotNull($youtube->getCaptionByMaterialId($material2->getId()));
        $this->assertNotNull($youtube->getCaptionByMaterialId($material3->getId()));

        $deleteOutput = [
            'error' => false,
        ];
        $this->youtubeProcessService->expects($this->any())
            ->method('deleteCaption')
            ->will($this->returnValue($deleteOutput))
        ;

        $captionIds1 = [];
        $captionIds1[] = $captionId2;
        $out2 = $this->captionService->deleteCaption($multimediaObject, $captionIds1);

        $this->assertNotNull($youtube->getCaptionByCaptionId($captionId1));
        $this->assertNull($youtube->getCaptionByCaptionId($captionId2));
        $this->assertNotNull($youtube->getCaptionByCaptionId($captionId3));

        $captionIds2 = [];
        $captionIds2[] = $captionId1;
        $captionIds2[] = $captionId3;
        $out3 = $this->captionService->deleteCaption($multimediaObject, $captionIds2);

        $this->assertNull($youtube->getCaptionByCaptionId($captionId1));
        $this->assertNull($youtube->getCaptionByCaptionId($captionId2));
        $this->assertNull($youtube->getCaptionByCaptionId($captionId3));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Error in retrieve captions list
     */
    public function testListAllException()
    {
        $multimediaObject = $this->initMultimediaObject();
        $youtube = $this->initYoutube($multimediaObject);

        $listOutput = [
            'error_out' => 'Not able to connect to server.',
            'error' => true,
        ];
        $this->youtubeProcessService->expects($this->any())
            ->method('listCaptions')
            ->will($this->returnValue($listOutput))
        ;

        $out = $this->captionService->listAllCaptions($multimediaObject);
        $this->assertEquals($listOutput['error_out'], $out);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Error in uploading Caption for Youtube video with id
     */
    public function testUploadCaptionException()
    {
        $multimediaObject = $this->initMultimediaObject();
        $youtube = $this->initYoutube($multimediaObject);

        $name = 'Caption in English';
        $language = 'en';
        $mimeType = 'vtt';
        $filename = 'caption_en.vtt';
        $material = $this->initMaterial($multimediaObject, $name, $language, $mimeType, $filename);

        $insertOutput = [
            'error_out' => 'Caption with that name already exists.',
            'error' => true,
        ];
        $this->youtubeProcessService->expects($this->any())
            ->method('insertCaption')
            ->will($this->returnValue($insertOutput))
        ;

        $materials = $multimediaObject->getMaterials();
        $materialIds = [];
        foreach ($materials as $material) {
            $materialIds[] = $material->getId();
        }
        $out = $this->captionService->uploadCaption($multimediaObject, $materialIds);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Error in deleting Caption for Youtube video with id
     */
    public function testDeleteCaptionException()
    {
        $multimediaObject = $this->initMultimediaObject();
        $youtube = $this->initYoutube($multimediaObject);

        $name = 'Caption in English';
        $language = 'en';
        $mimeType = 'vtt';
        $filename = 'caption_en.vtt';
        $material = $this->initMaterial($multimediaObject, $name, $language, $mimeType, $filename);

        $captionId = 'Youtube-Caption-Id';

        $insertOutput = [
            'out' => [
                'captionid' => $captionId,
                'name' => 'Caption in English',
                'language' => 'en',
                'is_draft' => false,
                'last_updated' => '2018-01-31 09:00:00',
            ],
            'error' => false,
        ];

        $this->youtubeProcessService->expects($this->any())
            ->method('insertCaption')
            ->will($this->returnValue($insertOutput))
        ;

        $materialIds = [];
        $materialIds[] = $material->getId();
        $out = $this->captionService->uploadCaption($multimediaObject, $materialIds);

        $deleteOutput = [
            'error_out' => 'Caption with that name does not exists.',
            'error' => true,
        ];
        $this->youtubeProcessService->expects($this->any())
            ->method('deleteCaption')
            ->will($this->returnValue($deleteOutput))
        ;

        $captionIds1 = [];
        $captionIds1[] = $captionId;
        $out2 = $this->captionService->deleteCaption($multimediaObject, $captionIds1);
    }

    public function initMaterial(MultimediaObject $multimediaObject, $name, $language, $mimeType, $filename)
    {
        $material = new Material();
        $material->setName($name);
        $material->setLanguage($language);
        $material->setMimeType($mimeType);
        $material->setPath($this->resourcesDir.$filename);
        $multimediaObject->addMaterial($material);
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        return $material;
    }

    public function initInsertOutput($captionId, $name, $language, $isDraft, $lastUpdatedString)
    {
        return [
            'out' => [
                'captionid' => $captionId,
                'name' => $name,
                'language' => $language,
                'is_draft' => $isDraft,
                'last_updated' => $lastUpdatedString,
            ],
            'error' => false,
        ];
    }

    private function initMultimediaObject()
    {
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

        $multimediaObject = $this->mmobjRepo->find($multimediaObject->getId());
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

        return $multimediaObject;
    }

    private function initYoutube(MultimediaObject $multimediaObject)
    {
        $youtube = new Youtube();
        $youtube->setMultimediaObjectId($multimediaObject->getId());
        $youtubeId = '12345678909';
        $youtubeUrl = 'https://www.youtube.com/watch?v='.$youtubeId;
        $youtube->setYoutubeAccount('account_test');
        $youtube->setYoutubeId($youtubeId);
        $youtube->setLink($youtubeUrl);
        $youtube->setStatus(Youtube::STATUS_PUBLISHED);
        $now = new \DateTime('now');
        $youtube->setSyncMetadataDate($now);
        $youtube->setUploadDate($now);
        $this->dm->persist($youtube);
        $this->dm->flush();
        $multimediaObject->setProperty('youtubeurl', $youtubeUrl);
        $multimediaObject->setProperty('youtube', $youtube->getId());
        $this->dm->persist($multimediaObject);
        $this->dm->flush();

        return $youtube;
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
