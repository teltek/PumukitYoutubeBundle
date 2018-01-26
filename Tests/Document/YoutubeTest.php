<?php

namespace Pumukit\YoutubeBundle\Tests\Document;

use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Document\Caption;

class YoutubeTest extends \PHPUnit_Framework_TestCase
{
    public function testSetterAndGetter()
    {
        $youtubeId = 'j7nFiNk157o';
        $link = 'https://www.youtube.com/watch?v=j7nFiNk157o';
        $embed = '<iframe width="560" height="315" src="https://www.youtube.com/embed/j7nFiNk157o" frameborder="0" allowfullscreen></iframe>';
        $status = Youtube::STATUS_UPLOADING;
        $playlist = 'PLmXxqSJJq-yUfrjvKe5c5LX_1x7nGVF6c';
        $playlistItem = 'PLAwsTYpyHbLgugOuQwrVaPIEnH3QiSoTyXoxRcXf9zm0';
        $force = true;
        $updatePlaylist = true;
        $multimediaObjectUpdateDate = new \DateTime('2015-08-14 03:05');
        $syncMetadataDate = new \DateTime('2015-08-14 04:05');

        $caption = new Caption();
        $language = 'es';
        $name = 'test';
        $materialId = 'materialId';
        $caption->setLanguage($language);
        $caption->setName($name);
        $caption->setMaterialId($materialId);

        $youtube = new Youtube();

        $youtube->setYoutubeId($youtubeId);
        $youtube->setLink($link);
        $youtube->setEmbed($embed);
        $youtube->setStatus($status);
        $youtube->setPlaylist($playlist, $playlistItem);
        $youtube->setForce($force);
        $youtube->setUpdatePlaylist($updatePlaylist);
        $youtube->setMultimediaObjectUpdateDate($multimediaObjectUpdateDate);
        $youtube->setSyncMetadataDate($syncMetadataDate);
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
        $this->assertEquals($caption, $youtube->getCaptionWithLanguage($language));
    }
}
