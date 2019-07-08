<?php

namespace Pumukit\YoutubeBundle\Tests\Document;

use PHPUnit\Framework\TestCase;
use Pumukit\YoutubeBundle\Document\Caption;

/**
 * @internal
 * @coversNothing
 */
class CaptionTest extends TestCase
{
    public function testSetterAndGetter()
    {
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

        $this->assertEquals($caption, $caption);
        $this->assertEquals($materialId, $caption->getMaterialId());
        $this->assertEquals($captionId, $caption->getCaptionId());
        $this->assertEquals($language, $caption->getLanguage());
        $this->assertEquals($name, $caption->getName());
        $this->assertEquals($isDraft, $caption->getIsDraft());
        $this->assertEquals($lastUpdated, $caption->getLastUpdated());
    }
}
