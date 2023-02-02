<?php

namespace Pumukit\YoutubeBundle\Tests\Repository;

use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Caption;
use Pumukit\YoutubeBundle\Document\Youtube;

/**
 * @internal
 *
 * @coversNothing
 */
class YoutubeRepositoryTest extends PumukitTestCase
{
    private $repo;

    public function setUp(): void
    {
        $options = ['environment' => 'test'];
        $kernel = static::createKernel($options);
        $kernel->boot();
        $this->dm = $kernel->getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->repo = $this->dm
            ->getRepository(Youtube::class)
        ;

        $this->dm->getDocumentCollection(Youtube::class)->deleteMany([]);
        $this->dm->getDocumentCollection(MultimediaObject::class)->deleteMany([]);
        $this->dm->flush();
    }

    public function testRepositoryEmpty()
    {
        $this->assertEquals(0, count($this->repo->findAll()));
    }

    public function testRepository()
    {
        $youtube1 = new Youtube();
        $this->dm->persist($youtube1);
        $this->dm->flush();

        $this->assertEquals(1, count($this->repo->findAll()));

        $youtube2 = new Youtube();
        $this->dm->persist($youtube2);
        $this->dm->flush();

        $this->assertEquals(2, count($this->repo->findAll()));
    }

    public function testGetWithAnyStatus()
    {
        $youtube1 = new Youtube();
        $youtube1->setStatus(Youtube::STATUS_ERROR);

        $youtube2 = new Youtube();
        $youtube2->setStatus(Youtube::STATUS_DEFAULT);

        $youtube3 = new Youtube();
        $youtube3->setStatus(Youtube::STATUS_UPLOADING);

        $youtube4 = new Youtube();
        $youtube4->setStatus(Youtube::STATUS_DUPLICATED);

        $this->dm->persist($youtube1);
        $this->dm->persist($youtube2);
        $this->dm->persist($youtube3);
        $this->dm->persist($youtube4);
        $this->dm->flush();

        $youtubes = [$youtube1, $youtube3];
        $statusArray = [Youtube::STATUS_ERROR, Youtube::STATUS_UPLOADING];
        $results = $this->repo->getWithAnyStatus($statusArray);
        $this->assertEquals($youtubes, array_values(iterator_to_array($results)));

        $youtubes = [$youtube2, $youtube3];
        $statusArray = [Youtube::STATUS_DEFAULT, Youtube::STATUS_UPLOADING];
        $results = $this->repo->getWithAnyStatus($statusArray);
        $this->assertEquals($youtubes, array_values(iterator_to_array($results)));

        $youtubes = [$youtube1, $youtube4];
        $statusArray = [Youtube::STATUS_ERROR, Youtube::STATUS_DUPLICATED];
        $results = $this->repo->getWithAnyStatus($statusArray);
        $this->assertEquals($youtubes, array_values(iterator_to_array($results)));
    }

    public function testGetDistinctMultimediaObjectIdsWithAnyStatus()
    {
        $mm1 = new MultimediaObject();
        $mm2 = new MultimediaObject();
        $mm3 = new MultimediaObject();
        $mm4 = new MultimediaObject();

        $this->dm->persist($mm1);
        $this->dm->persist($mm2);
        $this->dm->persist($mm3);
        $this->dm->persist($mm4);
        $this->dm->flush();

        $youtube1 = new Youtube();
        $youtube1->setStatus(Youtube::STATUS_ERROR);
        $youtube1->setMultimediaObjectId($mm1->getId());

        $youtube2 = new Youtube();
        $youtube2->setStatus(Youtube::STATUS_DEFAULT);
        $youtube2->setMultimediaObjectId($mm2->getId());

        $youtube3 = new Youtube();
        $youtube3->setStatus(Youtube::STATUS_UPLOADING);
        $youtube3->setMultimediaObjectId($mm3->getId());

        $youtube4 = new Youtube();
        $youtube4->setStatus(Youtube::STATUS_DUPLICATED);
        $youtube4->setMultimediaObjectId($mm4->getId());

        $this->dm->persist($youtube1);
        $this->dm->persist($youtube2);
        $this->dm->persist($youtube3);
        $this->dm->persist($youtube4);
        $this->dm->flush();

        $mmIds = [$youtube1->getMultimediaObjectId(), $youtube3->getMultimediaObjectId()];
        $statusArray = [Youtube::STATUS_ERROR, Youtube::STATUS_UPLOADING];
        $results = $this->repo->getDistinctMultimediaObjectIdsWithAnyStatus($statusArray);
        $this->assertEquals($mmIds, $results);

        $mmIds = [$youtube2->getMultimediaObjectId(), $youtube3->getMultimediaObjectId()];
        $statusArray = [Youtube::STATUS_DEFAULT, Youtube::STATUS_UPLOADING];
        $results = $this->repo->getDistinctMultimediaObjectIdsWithAnyStatus($statusArray);
        $this->assertEquals($mmIds, $results);

        $mmIds = [$youtube1->getMultimediaObjectId(), $youtube4->getMultimediaObjectId()];
        $statusArray = [Youtube::STATUS_ERROR, Youtube::STATUS_DUPLICATED];
        $results = $this->repo->getDistinctMultimediaObjectIdsWithAnyStatus($statusArray);
        $this->assertEquals($mmIds, $results);
    }

    public function testGetWithStatusAndForce()
    {
        $youtube1 = new Youtube();
        $youtube1->setStatus(Youtube::STATUS_ERROR);
        $youtube1->setForce(true);

        $youtube2 = new Youtube();
        $youtube2->setStatus(Youtube::STATUS_DEFAULT);
        $youtube2->setForce(false);

        $youtube3 = new Youtube();
        $youtube3->setStatus(Youtube::STATUS_ERROR);
        $youtube3->setForce(false);

        $youtube4 = new Youtube();
        $youtube4->setStatus(Youtube::STATUS_DEFAULT);
        $youtube4->setForce(true);

        $youtube5 = new Youtube();
        $youtube5->setStatus(Youtube::STATUS_ERROR);
        $youtube5->setForce(false);

        $this->dm->persist($youtube1);
        $this->dm->persist($youtube2);
        $this->dm->persist($youtube3);
        $this->dm->persist($youtube4);
        $this->dm->persist($youtube5);
        $this->dm->flush();

        $youtubes = [$youtube1];
        $status = Youtube::STATUS_ERROR;
        $results = $this->repo->getWithStatusAndForce($status, true);
        $this->assertEquals($youtubes, array_values(iterator_to_array($results)));

        $youtubes = [$youtube4];
        $status = Youtube::STATUS_DEFAULT;
        $results = $this->repo->getWithStatusAndForce($status, true);
        $this->assertEquals($youtubes, array_values(iterator_to_array($results)));

        $youtubes = [$youtube2];
        $status = Youtube::STATUS_DEFAULT;
        $results = $this->repo->getWithStatusAndForce($status, false);
        $this->assertEquals($youtubes, array_values(iterator_to_array($results)));

        $youtubes = [$youtube3, $youtube5];
        $status = Youtube::STATUS_ERROR;
        $results = $this->repo->getWithStatusAndForce($status, false);
        $this->assertEquals($youtubes, array_values(iterator_to_array($results)));
    }

    public function testGetDistinctMultimediaObjectIdsWithStatusAndForce()
    {
        $mm1 = new MultimediaObject();
        $mm2 = new MultimediaObject();
        $mm3 = new MultimediaObject();
        $mm4 = new MultimediaObject();

        $this->dm->persist($mm1);
        $this->dm->persist($mm2);
        $this->dm->persist($mm3);
        $this->dm->persist($mm4);
        $this->dm->flush();

        $youtube1 = new Youtube();
        $youtube1->setStatus(Youtube::STATUS_ERROR);
        $youtube1->setForce(true);
        $youtube1->setMultimediaObjectId($mm1->getId());

        $youtube2 = new Youtube();
        $youtube2->setStatus(Youtube::STATUS_DEFAULT);
        $youtube2->setForce(false);
        $youtube2->setMultimediaObjectId($mm2->getId());

        $youtube3 = new Youtube();
        $youtube3->setStatus(Youtube::STATUS_ERROR);
        $youtube3->setForce(false);
        $youtube3->setMultimediaObjectId($mm3->getId());

        $youtube4 = new Youtube();
        $youtube4->setStatus(Youtube::STATUS_DEFAULT);
        $youtube4->setForce(true);
        $youtube4->setMultimediaObjectId($mm4->getId());

        $youtube5 = new Youtube();
        $youtube5->setStatus(Youtube::STATUS_ERROR);
        $youtube5->setForce(false);
        $youtube5->setMultimediaObjectId($mm3->getId());

        $this->dm->persist($youtube1);
        $this->dm->persist($youtube2);
        $this->dm->persist($youtube3);
        $this->dm->persist($youtube4);
        $this->dm->persist($youtube5);
        $this->dm->flush();

        $mmIds = [$mm1->getId()];
        $status = Youtube::STATUS_ERROR;
        $results = $this->repo->getDistinctMultimediaObjectIdsWithStatusAndForce($status, true);
        $this->assertEquals($mmIds, $results);

        $mmIds = [$mm2->getId()];
        $status = Youtube::STATUS_DEFAULT;
        $results = $this->repo->getDistinctMultimediaObjectIdsWithStatusAndForce($status, false);
        $this->assertEquals($mmIds, $results);

        $mmIds = [$mm4->getId()];
        $status = Youtube::STATUS_DEFAULT;
        $results = $this->repo->getDistinctMultimediaObjectIdsWithStatusAndForce($status, true);
        $this->assertEquals($mmIds, $results);

        $mmIds = [$mm3->getId()];
        $status = Youtube::STATUS_ERROR;
        $results = $this->repo->getDistinctMultimediaObjectIdsWithStatusAndForce($status, false);
        $this->assertEquals($mmIds, $results);
    }

    public function testGetDistinctFieldWithStatusAndForce()
    {
        $link1 = 'https://www.youtube.com/watch?v=v6yiPnzHCEA';
        $link2 = 'https://www.youtube.com/watch?v=my6bfA14vMQ';

        $youtube1 = new Youtube();
        $youtube1->setStatus(Youtube::STATUS_ERROR);
        $youtube1->setForce(true);
        $youtube1->setLink($link1);

        $youtube2 = new Youtube();
        $youtube2->setStatus(Youtube::STATUS_DEFAULT);
        $youtube2->setForce(false);
        $youtube2->setLink($link1);

        $youtube3 = new Youtube();
        $youtube3->setStatus(Youtube::STATUS_ERROR);
        $youtube3->setForce(false);
        $youtube3->setLink($link2);

        $youtube4 = new Youtube();
        $youtube4->setStatus(Youtube::STATUS_DEFAULT);
        $youtube4->setForce(true);
        $youtube4->setLink($link2);

        $youtube5 = new Youtube();
        $youtube5->setStatus(Youtube::STATUS_ERROR);
        $youtube5->setForce(false);
        $youtube5->setLink($link1);

        $this->dm->persist($youtube1);
        $this->dm->persist($youtube2);
        $this->dm->persist($youtube3);
        $this->dm->persist($youtube4);
        $this->dm->persist($youtube5);
        $this->dm->flush();

        $links = [$link1];
        $status = Youtube::STATUS_ERROR;
        $results = $this->repo->getDistinctFieldWithStatusAndForce('link', $status, true);
        $this->assertEquals($links, $results);

        $links = [$link1];
        $status = Youtube::STATUS_DEFAULT;
        $results = $this->repo->getDistinctFieldWithStatusAndForce('link', $status, false);
        $this->assertEquals($links, $results);

        $links = [$link2];
        $status = Youtube::STATUS_DEFAULT;
        $results = $this->repo->getDistinctFieldWithStatusAndForce('link', $status, true);
        $this->assertEquals($links, $results);

        $links = [$link2, $link1];
        $status = Youtube::STATUS_ERROR;
        $results = $this->repo->getDistinctFieldWithStatusAndForce('link', $status, false);
        $this->assertEquals($links, $results);
    }

    public function testGetNotMetadataUpdated()
    {
        $multimediaObjectSyncMetadataDate = new \DateTime('2015-08-14 04:15');

        $youtube1 = new Youtube();
        $youtube1->setMultimediaObjectUpdateDate(new \DateTime('2015-08-15 04:09'));
        $youtube1->setSyncMetadataDate($multimediaObjectSyncMetadataDate);

        $youtube2 = new Youtube();
        $youtube2->setMultimediaObjectUpdateDate(new \DateTime('2015-08-12 04:09'));
        $youtube2->setSyncMetadataDate($multimediaObjectSyncMetadataDate);

        $youtube3 = new Youtube();
        $youtube3->setMultimediaObjectUpdateDate(new \DateTime('2015-08-16 04:09'));
        $youtube3->setSyncMetadataDate($multimediaObjectSyncMetadataDate);

        $youtube4 = new Youtube();
        $youtube4->setMultimediaObjectUpdateDate(new \DateTime('2015-08-10 04:09'));
        $youtube4->setSyncMetadataDate($multimediaObjectSyncMetadataDate);

        $this->dm->persist($youtube1);
        $this->dm->persist($youtube2);
        $this->dm->persist($youtube3);
        $this->dm->persist($youtube4);
        $this->dm->flush();

        $youtubes = $this->repo->getNotMetadataUpdated();
        $youtubesArray = iterator_to_array($youtubes);
        $this->assertTrue(in_array($youtube1, $youtubesArray));
        $this->assertFalse(in_array($youtube2, $youtubesArray));
        $this->assertTrue(in_array($youtube3, $youtubesArray));
        $this->assertFalse(in_array($youtube4, $youtubesArray));
    }

    public function testGetDistinctIdsNotMetadataUpdated()
    {
        $multimediaObjectSyncMetadataDate = new \DateTime('2015-08-14 04:15');
        $youtube1 = new Youtube();
        $youtube1->setMultimediaObjectUpdateDate(new \DateTime('2015-08-15 04:09'));
        $youtube1->setSyncMetadataDate($multimediaObjectSyncMetadataDate);

        $youtube2 = new Youtube();
        $youtube2->setMultimediaObjectUpdateDate(new \DateTime('2015-08-12 04:09'));
        $youtube2->setSyncMetadataDate($multimediaObjectSyncMetadataDate);

        $youtube3 = new Youtube();
        $youtube3->setMultimediaObjectUpdateDate(new \DateTime('2015-08-16 04:09'));
        $youtube3->setSyncMetadataDate($multimediaObjectSyncMetadataDate);

        $youtube4 = new Youtube();
        $youtube4->setMultimediaObjectUpdateDate(new \DateTime('2015-08-20 04:09'));
        $youtube4->setSyncMetadataDate($multimediaObjectSyncMetadataDate);

        $this->dm->persist($youtube1);
        $this->dm->persist($youtube2);
        $this->dm->persist($youtube3);
        $this->dm->persist($youtube4);
        $this->dm->flush();

        $youtubeIds = $this->repo->getDistinctIdsNotMetadataUpdated();
        $youtubeIdsArray = $youtubeIds;
        $this->assertTrue(in_array($youtube1->getId(), $youtubeIdsArray));
        $this->assertFalse(in_array($youtube2->getId(), $youtubeIdsArray));
        $this->assertTrue(in_array($youtube3->getId(), $youtubeIdsArray));
        $this->assertTrue(in_array($youtube4->getId(), $youtubeIdsArray));
    }

    public function testRemoveCaptionById()
    {
        $caption1 = new Caption();
        $caption2 = new Caption();
        $caption3 = new Caption();

        $youtube = new Youtube();
        $youtube->addCaption($caption1);
        $youtube->addCaption($caption2);
        $youtube->addCaption($caption3);

        $this->dm->persist($youtube);
        $this->dm->flush();

        $this->assertEquals($caption1, $youtube->getCaptionById($caption1->getId()));
        $this->assertEquals($caption2, $youtube->getCaptionById($caption2->getId()));
        $this->assertEquals($caption3, $youtube->getCaptionById($caption3->getId()));

        $youtube->removeCaptionById($caption1->getId());
        $this->dm->persist($youtube);
        $this->dm->flush();

        $this->assertNull($youtube->getCaptionById($caption1->getId()));
        $this->assertEquals($caption2, $youtube->getCaptionById($caption2->getId()));
        $this->assertEquals($caption3, $youtube->getCaptionById($caption3->getId()));
    }
}
