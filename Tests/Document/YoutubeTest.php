<?php
namespace Pumukit\YoutubeBundle\Tests\Document;

use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeTest extends \PHPUnit_Framework_TestCase
{
    public function testGetterAndSetter()
    {
        $youtubeId = "j7nFiNk157o";
        $link = "https://www.youtube.com/watch?v=j7nFiNk157o";
        $embed = '<iframe width="560" height="315" src="https://www.youtube.com/embed/j7nFiNk157o" frameborder="0" allowfullscreen></iframe>';
        $status = Youtube::STATUS_UPLOADING;
        $playlist = "w7dD-JJJytM&list=PLmXxqSJJq-yUfrjvKe5c5LX_1x7nGVF6c";
        $force = true;
        $updatePlaylist = true;

        $youtube = new Youtube();

        $youtube->setYoutubeId($youtubeId);
        $youtube->setLink($link);
        $youtube->setEmbed($embed);
        $youtube->setStatus($status);
        $youtube->setPlaylist($playlist);
        $youtube->setForce($force);
        $youtube->setUpdatePlaylist($updatePlaylist);

        $this->assertEquals($youtubeId, $youtube->getYoutubeId());
        $this->assertEquals($link, $youtube->getLink());
        $this->assertEquals($embed, $youtube->getEmbed());
        $this->assertEquals($status, $youtube->getStatus());
        $this->assertEquals($playlist, $youtube->getPlaylist());
        $this->assertEquals($force, $youtube->getForce());
        $this->assertEquals($updatePlaylist, $youtube->getUpdatePlaylist());
    }
}