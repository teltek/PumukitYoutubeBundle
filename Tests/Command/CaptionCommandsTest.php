<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use PHPUnit\Framework\Attributes\Group;
use Pumukit\YoutubeBundle\Document\Caption;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class CaptionCommandsTest extends YoutubeTestCase
{
    public function testACaptionIsUploadedAndNoted(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $material = $this->material($multimediaObject);
        $this->willAcceptACaption('caption1');

        $this->runCommand('pumukit:youtube:caption:upload');

        $captions = $this->youtubeOf($multimediaObject)->getCaptions();
        $this->assertCount(1, $captions);
        $this->assertSame('caption1', $captions[0]->getCaptionId());
        $this->assertSame($material->getId(), $captions[0]->getMaterialId());
    }

    public function testWhatIsNotACaptionForYoutubeIsSkipped(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->material($multimediaObject, 'es', 'pdf');
        $hidden = $this->material($multimediaObject, 'en');
        $hidden->setHide(true);
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(0, $this->api->calls());
    }

    public function testACaptionAlreadyUploadedIsNotSentAgain(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption($this->material($multimediaObject)->getId(), 'caption1'));
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(0, $this->api->calls());
    }

    public function testAVideoNotPublishedYetWaits(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject, Youtube::STATUS_PROCESSING);
        $this->material($multimediaObject);

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(0, $this->api->calls());
    }

    public function testAnErrorIsNotedApart(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->material($multimediaObject);
        $this->api->willFail('captionExists', 409);

        $this->runCommand('pumukit:youtube:caption:upload');

        $youtube = $this->youtubeOf($multimediaObject);
        $this->assertSame('captionExists', $youtube->getCaptionUpdateError()->id());
        $this->assertCount(0, $youtube->getCaptions());
    }

    public function testACaptionThatAlreadyExistsIsReplacedOnTheNextRun(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $material = $this->material($multimediaObject);
        $youtube->setCaptionUpdateError(Error::create('captionExists', 'exists', new \DateTime(), []));
        $this->dm->flush();
        $this->api->willAnswer(['items' => [['id' => 'old', 'snippet' => ['name' => $material->getName(), 'language' => 'es']]]]);
        $this->api->willAnswerEmpty();
        $this->willAcceptACaption('caption2');

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(1, $this->api->callsTo('DELETE', '/captions'));
        $stored = $this->youtubeOf($multimediaObject);
        $this->assertNull($stored->getCaptionUpdateError());
        $this->assertSame('caption2', $stored->getCaptions()[0]->getCaptionId());
    }

    #[Group('to-change')]
    public function testAnErrorWithoutJsonLosesWhatWasAlreadyUploaded(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->material($multimediaObject, 'es');
        $this->material($multimediaObject, 'en');
        $this->willAcceptACaption('caption1');
        $this->api->willFailWithoutJson();

        $tester = $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(2, $this->api->callsTo('POST', '/captions'));
        $this->assertCount(0, $this->youtubeOf($multimediaObject)->getCaptions());
        $this->assertStringContainsString('Upload caption of video', $tester->getDisplay());
    }

    #[Group('to-change')]
    public function testTheQuotaDoesNotStopTheRun(): void
    {
        foreach ([1, 2] as $ignored) {
            $multimediaObject = $this->videoForYoutube();
            $this->youtubeDocument($multimediaObject);
            $this->material($multimediaObject);
            $this->api->willFail('quotaExceeded', 403);
        }

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(2, $this->api->calls());
        $this->assertSame('quotaExceeded', $this->youtubeOf($multimediaObject)->getCaptionUpdateError()->id());
    }

    public function testACaptionWithoutItsMaterialIsDeleted(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption('aaaaaaaaaaaaaaaaaaaaaaaa', 'caption1'));
        $this->dm->flush();
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:caption:delete');

        $this->assertSame(1, $this->api->callsTo('DELETE', '/captions'));
        $this->assertCount(0, $this->youtubeOf($multimediaObject)->getCaptions());
    }

    public function testACaptionOfAHiddenMaterialIsDeleted(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $material = $this->material($multimediaObject);
        $material->setHide(true);
        $youtube->addCaption($this->caption($material->getId(), 'caption1'));
        $this->dm->flush();
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:caption:delete');

        $this->assertCount(0, $this->youtubeOf($multimediaObject)->getCaptions());
    }

    public function testACaptionOfAVisibleMaterialStays(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption($this->material($multimediaObject)->getId(), 'caption1'));
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:caption:delete');

        $this->assertSame(0, $this->api->calls());
    }

    #[Group('to-change')]
    public function testAFailedDeleteForgetsTheError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption('aaaaaaaaaaaaaaaaaaaaaaaa', 'caption1'));
        $this->dm->flush();
        $this->api->willFail('forbidden', 403);

        $this->runCommand('pumukit:youtube:caption:delete');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertCount(1, $stored->getCaptions());
        $this->assertNull($stored->getCaptionUpdateError());
    }

    private function willAcceptACaption(string $id): void
    {
        $this->api->willAnswer(['kind' => 'youtube#caption', 'id' => $id, 'snippet' => ['name' => 'Caption', 'language' => 'es', 'lastUpdated' => '2026-01-01T10:00:00Z', 'isDraft' => false]]);
    }

    private function caption(string $materialId, string $captionId): Caption
    {
        $caption = new Caption();
        $caption->setMaterialId($materialId);
        $caption->setCaptionId($captionId);
        $caption->setName('Caption');
        $caption->setLastUpdated(new \DateTime());

        return $caption;
    }
}
