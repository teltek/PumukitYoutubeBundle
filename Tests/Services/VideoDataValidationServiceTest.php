<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Services;

use Pumukit\YoutubeBundle\Services\VideoDataValidationService;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class VideoDataValidationServiceTest extends YoutubeTestCase
{
    public function testAShortTitleIsKept(): void
    {
        $multimediaObject = $this->video();

        $this->assertSame($multimediaObject->getTitle(), $this->service()->getTitleForYoutube($multimediaObject));
    }

    public function testALongTitleIsCutOnAWord(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setTitle(str_repeat('word ', 40));

        $title = $this->service()->getTitleForYoutube($multimediaObject);

        $this->assertLessThanOrEqual(100, strlen($title));
        $this->assertStringEndsWith('word(...)', $title);
    }

    public function testKeywordsAreJoinedWithoutAngleBrackets(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setKeywords(['one', '<two>'], 'en');

        $this->assertSame('one,two', $this->service()->getTagsForYoutube($multimediaObject));
    }

    public function testTheDescriptionHasTheSeriesAndNoHtml(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setDescription('First<br />second <b>bold</b>');

        $description = $this->service()->getDescriptionForYoutube($multimediaObject);

        $this->assertStringContainsString('Series', $description);
        $this->assertStringContainsString("First\nsecond bold", $description);
    }

    public function testALongTitleWithoutSpacesIsCut(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setTitle(str_repeat('a', 120));

        $this->assertSame(str_repeat('a', 95).'(...)', $this->service()->getTitleForYoutube($multimediaObject));
    }

    public function testATitleIsCutByCharactersAndKeepsThemWhole(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setTitle(str_repeat('ñ', 120));

        $title = $this->service()->getTitleForYoutube($multimediaObject);

        $this->assertSame(100, mb_strlen($title));
        $this->assertTrue(mb_check_encoding($title, 'UTF-8'));
    }

    public function testTheTitleAndTheDescriptionHaveNoAngleBrackets(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setTitle('1 < 2 > 0');
        $multimediaObject->setDescription('3 < 4');

        $this->assertSame('1  2  0', $this->service()->getTitleForYoutube($multimediaObject));
        $this->assertStringNotContainsString('<', $this->service()->getDescriptionForYoutube($multimediaObject));
    }

    public function testADescriptionOverTheLimitOfYoutubeIsCut(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setDescription(str_repeat('ñ', 6000));

        $description = $this->service()->getDescriptionForYoutube($multimediaObject);

        $this->assertLessThanOrEqual(5000, strlen($description));
        $this->assertTrue(mb_check_encoding($description, 'UTF-8'));
    }

    public function testACutDescriptionKeepsTheLinkToTheVideo(): void
    {
        $multimediaObject = $this->video([$this->tag('PUCHWEBTV')]);
        $multimediaObject->setDescription(str_repeat('a', 6000));

        $description = $this->service()->getDescriptionForYoutube($multimediaObject);

        $this->assertLessThanOrEqual(5000, strlen($description));
        $this->assertStringContainsString('/video/'.$multimediaObject->getId(), $description);
    }

    private function service(): VideoDataValidationService
    {
        return static::getContainer()->get(VideoDataValidationService::class);
    }
}
