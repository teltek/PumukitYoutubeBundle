<?php

namespace Pumukit\YoutubeBundle\Tests\Document;

use PHPUnit\Framework\TestCase;
use Pumukit\YoutubeBundle\Document\Caption;
use Pumukit\YoutubeBundle\Document\Youtube;

/**
 * @internal
 *
 * @coversNothing
 */
class YoutubeTest extends TestCase
{
    public function testSetterAndGetter(): void
    {
        $youtubeId = 'j7nFiNk157o';
        $link = 'https://www.youtube.com/watch?v=j7nFiNk157o';
        $embed = '<iframe width="560" height="315" src="https://www.youtube.com/embed/j7nFiNk157o" allowfullscreen></iframe>';
        $status = Youtube::STATUS_UPLOADING;
        $playlist = 'PLmXxqSJJq-yUfrjvKe5c5LX_1x7nGVF6c';
        $playlistItem = 'PLAwsTYpyHbLgugOuQwrVaPIEnH3QiSoTyXoxRcXf9zm0';
        $force = true;
        $updatePlaylist = true;
        $multimediaObjectUpdateDate = new \DateTime('2015-08-14 03:05');
        $syncMetadataDate = new \DateTime('2015-08-14 04:05');
        $syncCaptionsDate = new \DateTime('2015-08-14 04:15');
        $fileUploaded = 'video.mp4';
        $youtubeAccount = 'account_test';

        $caption = new Caption();
        $materialId = 'materialId';
        $captionId = 'PLP01yHXb6beuKo1OkzvMyosKpseuN9eXPRaaOFlurh_9QaY6CXRXQ==';
        $language = 'es';
        $name = 'test';
        $isDraft = false;
        $lastUpdated = new \DateTime('2018-01-01 12:00');
        $caption->setMaterialId($materialId);
        $caption->setCaptionId($captionId);
        $caption->setLanguage($language);
        $caption->setName($name);
        $caption->setIsDraft($isDraft);
        $caption->setLastUpdated($lastUpdated);

        $youtube = new Youtube();

        $youtube->setYoutubeId($youtubeId);
        $youtube->setYoutubeAccount($youtubeAccount);
        $youtube->setLink($link);
        $youtube->setEmbed($embed);
        $youtube->setStatus($status);
        $youtube->setPlaylist($playlist, $playlistItem);
        $youtube->setForce($force);
        $youtube->setUpdatePlaylist($updatePlaylist);
        $youtube->setMultimediaObjectUpdateDate($multimediaObjectUpdateDate);
        $youtube->setSyncMetadataDate($syncMetadataDate);
        $youtube->setSyncCaptionsDate($syncCaptionsDate);
        $youtube->setFileUploaded($fileUploaded);
        $youtube->addCaption($caption);

        $this->assertEquals($youtubeId, $youtube->getYoutubeId());
        $this->assertEquals($link, $youtube->getLink());
        $this->assertEquals($embed, $youtube->getEmbed());
        $this->assertEquals($status, $youtube->getStatus());
        $this->assertEquals($playlistItem, $youtube->getPlaylist($playlist));
        $this->assertEquals($force, $youtube->getForce());
        $this->assertEquals($updatePlaylist, $youtube->getUpdatePlaylist());
        $this->assertEquals($multimediaObjectUpdateDate, $youtube->getMultimediaObjectUpdateDate());
        $this->assertEquals($syncMetadataDate, $youtube->getSyncMetadataDate());
        $this->assertEquals($syncCaptionsDate, $youtube->getSyncCaptionsDate());
        $this->assertEquals($caption, $youtube->getCaptionByLanguage($language));
        $this->assertEquals($fileUploaded, $youtube->getFileUploaded());
        $this->assertEquals($youtubeAccount, $youtube->getYoutubeAccount());

        $yCaption = $youtube->getCaptions()[0];

        $this->assertEquals($caption, $yCaption);
        $this->assertEquals($materialId, $yCaption->getMaterialId());
        $this->assertEquals($captionId, $yCaption->getCaptionId());
        $this->assertEquals($language, $yCaption->getLanguage());
        $this->assertEquals($name, $yCaption->getName());
        $this->assertEquals($isDraft, $yCaption->getIsDraft());
        $this->assertEquals($lastUpdated, $yCaption->getLastUpdated());

        $youtube->removeCaption($caption);

        $this->assertEmpty($youtube->getCaptions());
    }

    public function testGetAndRemoveCaptionByCaptionId(): void
    {
        $caption1 = new Caption();
        $captionId1 = 'captionid1';
        $caption1->setCaptionId($captionId1);

        $caption2 = new Caption();
        $captionId2 = 'captionid2';
        $caption2->setCaptionId($captionId2);

        $caption3 = new Caption();
        $captionId3 = 'captionid3';
        $caption3->setCaptionId($captionId3);

        $youtube = new Youtube();
        $youtube->addCaption($caption1);
        $youtube->addCaption($caption2);
        $youtube->addCaption($caption3);

        $this->assertEquals($caption1, $youtube->getCaptionByCaptionId($captionId1));
        $this->assertEquals($caption2, $youtube->getCaptionByCaptionId($captionId2));
        $this->assertEquals($caption3, $youtube->getCaptionByCaptionId($captionId3));

        $this->assertNotEquals($caption1, $youtube->getCaptionByCaptionId($captionId2));
        $this->assertNotEquals($caption2, $youtube->getCaptionByCaptionId($captionId3));
        $this->assertNotEquals($caption3, $youtube->getCaptionByCaptionId($captionId1));

        $youtube->removeCaptionByCaptionId($captionId2);
        $this->assertEquals($caption1, $youtube->getCaptionByCaptionId($captionId1));
        $this->assertNull($youtube->getCaptionByCaptionId($captionId2));
        $this->assertNotEquals($caption2, $youtube->getCaptionByCaptionId($captionId2));
        $this->assertEquals($caption3, $youtube->getCaptionByCaptionId($captionId3));

        $youtube->removeCaptionByCaptionId($captionId1);
        $this->assertNull($youtube->getCaptionByCaptionId($captionId1));
        $this->assertNotEquals($caption1, $youtube->getCaptionByCaptionId($captionId1));
        $this->assertNull($youtube->getCaptionByCaptionId($captionId2));
        $this->assertNotEquals($caption2, $youtube->getCaptionByCaptionId($captionId2));
        $this->assertEquals($caption3, $youtube->getCaptionByCaptionId($captionId3));

        $youtube->removeCaptionByCaptionId($captionId3);
        $this->assertNull($youtube->getCaptionByCaptionId($captionId1));
        $this->assertNotEquals($caption1, $youtube->getCaptionByCaptionId($captionId1));
        $this->assertNull($youtube->getCaptionByCaptionId($captionId2));
        $this->assertNotEquals($caption2, $youtube->getCaptionByCaptionId($captionId2));
        $this->assertNull($youtube->getCaptionByCaptionId($captionId3));
        $this->assertNotEquals($caption3, $youtube->getCaptionByCaptionId($captionId3));
    }

    public function testGetAndRemoveCaptionByMaterialId(): void
    {
        $caption1 = new Caption();
        $materialId1 = 'materialid1';
        $caption1->setMaterialId($materialId1);

        $caption2 = new Caption();
        $materialId2 = 'materialid2';
        $caption2->setMaterialId($materialId2);

        $caption3 = new Caption();
        $materialId3 = 'materialid3';
        $caption3->setMaterialId($materialId3);

        $youtube = new Youtube();
        $youtube->addCaption($caption1);
        $youtube->addCaption($caption2);
        $youtube->addCaption($caption3);

        $this->assertEquals($caption1, $youtube->getCaptionByMaterialId($materialId1));
        $this->assertEquals($caption2, $youtube->getCaptionByMaterialId($materialId2));
        $this->assertEquals($caption3, $youtube->getCaptionByMaterialId($materialId3));

        $this->assertNotEquals($caption1, $youtube->getCaptionByMaterialId($materialId2));
        $this->assertNotEquals($caption2, $youtube->getCaptionByMaterialId($materialId3));
        $this->assertNotEquals($caption3, $youtube->getCaptionByMaterialId($materialId1));

        $youtube->removeCaptionByMaterialId($materialId2);
        $this->assertEquals($caption1, $youtube->getCaptionByMaterialId($materialId1));
        $this->assertNull($youtube->getCaptionByMaterialId($materialId2));
        $this->assertNotEquals($caption2, $youtube->getCaptionByMaterialId($materialId2));
        $this->assertEquals($caption3, $youtube->getCaptionByMaterialId($materialId3));

        $youtube->removeCaptionByMaterialId($materialId1);
        $this->assertNull($youtube->getCaptionByMaterialId($materialId1));
        $this->assertNotEquals($caption1, $youtube->getCaptionByMaterialId($materialId1));
        $this->assertNull($youtube->getCaptionByMaterialId($materialId2));
        $this->assertNotEquals($caption2, $youtube->getCaptionByMaterialId($materialId2));
        $this->assertEquals($caption3, $youtube->getCaptionByMaterialId($materialId3));

        $youtube->removeCaptionByMaterialId($materialId3);
        $this->assertNull($youtube->getCaptionByMaterialId($materialId1));
        $this->assertNotEquals($caption1, $youtube->getCaptionByMaterialId($materialId1));
        $this->assertNull($youtube->getCaptionByMaterialId($materialId2));
        $this->assertNotEquals($caption2, $youtube->getCaptionByMaterialId($materialId2));
        $this->assertNull($youtube->getCaptionByMaterialId($materialId3));
        $this->assertNotEquals($caption3, $youtube->getCaptionByMaterialId($materialId3));
    }

    public function testContainsCaption(): void
    {
        $caption1 = new Caption();
        $materialId1 = 'materialid1';
        $caption1->setMaterialId($materialId1);

        $caption2 = new Caption();
        $materialId2 = 'materialid2';
        $caption2->setMaterialId($materialId2);

        $caption3 = new Caption();
        $materialId3 = 'materialid3';
        $caption3->setMaterialId($materialId3);

        $youtube = new Youtube();
        $youtube->addCaption($caption1);
        $youtube->addCaption($caption3);

        $this->assertTrue($youtube->containsCaption($caption1));
        $this->assertFalse($youtube->containsCaption($caption2));
        $this->assertTrue($youtube->containsCaption($caption3));
    }

    public function testGetCaptionsByLanguage(): void
    {
        $caption1 = new Caption();
        $language1 = 'en';
        $caption1->setLanguage($language1);

        $caption2 = new Caption();
        $language2 = 'en';
        $caption2->setLanguage($language2);

        $caption3 = new Caption();
        $language3 = 'es';
        $caption3->setLanguage($language3);

        $youtube = new Youtube();
        $youtube->addCaption($caption1);
        $youtube->addCaption($caption2);
        $youtube->addCaption($caption3);

        $this->assertEquals($caption1, $youtube->getCaptionByLanguage($language1));
        $this->assertEquals($caption2, $youtube->getCaptionByLanguage($language2));
        $this->assertEquals($caption3, $youtube->getCaptionByLanguage($language3));

        $this->assertContains($caption1, $youtube->getCaptionsByLanguage($language1));
        $this->assertContains($caption2, $youtube->getCaptionsByLanguage($language1));
        $this->assertNotContains($caption3, $youtube->getCaptionsByLanguage($language1));

        $this->assertContains($caption1, $youtube->getCaptionsByLanguage($language2));
        $this->assertContains($caption2, $youtube->getCaptionsByLanguage($language2));
        $this->assertNotContains($caption3, $youtube->getCaptionsByLanguage($language2));

        $this->assertNotContains($caption1, $youtube->getCaptionsByLanguage($language3));
        $this->assertNotContains($caption2, $youtube->getCaptionsByLanguage($language3));
        $this->assertContains($caption3, $youtube->getCaptionsByLanguage($language3));
    }
}
