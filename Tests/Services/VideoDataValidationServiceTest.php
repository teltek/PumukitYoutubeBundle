<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Services;

use PHPUnit\Framework\Attributes\Group;
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

    #[Group('to-change')]
    public function testALongTitleWithoutSpacesFails(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setTitle(str_repeat('a', 120));

        $this->expectException(\TypeError::class);

        $this->service()->getTitleForYoutube($multimediaObject);
    }

    #[Group('to-change')]
    public function testADescriptionOverTheLimitOfYoutubeIsNotCut(): void
    {
        $multimediaObject = $this->video();
        $multimediaObject->setDescription(str_repeat('a', 6000));

        $this->assertGreaterThan(5000, strlen($this->service()->getDescriptionForYoutube($multimediaObject)));
    }

    private function service(): VideoDataValidationService
    {
        return static::getContainer()->get(VideoDataValidationService::class);
    }
}
