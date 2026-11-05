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

    public function testAFailedDeleteKeepsTheError(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption('aaaaaaaaaaaaaaaaaaaaaaaa', 'caption1'));
        $this->dm->flush();
        $this->api->willFail('forbidden', 403);

        $this->runCommand('pumukit:youtube:caption:delete');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertCount(1, $stored->getCaptions());
        $this->assertSame('forbidden', $stored->getCaptionUpdateError()->id());
    }

    public function testACaptionAlreadyGoneFromYoutubeIsForgotten(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption('aaaaaaaaaaaaaaaaaaaaaaaa', 'caption1'));
        $this->dm->flush();
        $this->api->willFail('captionNotFound', 404);

        $this->runCommand('pumukit:youtube:caption:delete');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertCount(0, $stored->getCaptions());
        $this->assertNull($stored->getCaptionUpdateError());
    }

    public function testANewMaterialOfTheSameLanguageReplacesTheCaptionInOnePass(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $first = $this->material($multimediaObject);
        $youtube->addCaption($this->caption($first->getId(), 'caption1', 'es'));
        $this->dm->flush();
        $second = $this->material($multimediaObject);
        $this->api->willAnswerEmpty();
        $this->willAcceptACaption('caption2');

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(1, $this->api->callsTo('DELETE', '/captions'));
        $this->assertSame(1, $this->api->callsTo('POST', '/captions'));
        $captions = $this->youtubeOf($multimediaObject)->getCaptions();
        $this->assertCount(1, $captions);
        $this->assertSame('caption2', $captions[0]->getCaptionId());
        $this->assertSame($second->getId(), $captions[0]->getMaterialId());
    }

    public function testOnlyTheLatestMaterialOfALanguageIsUploaded(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->material($multimediaObject);
        $latest = $this->material($multimediaObject);
        $this->willAcceptACaption('caption1');

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(1, $this->api->callsTo('POST', '/captions'));
        $this->assertSame($latest->getId(), $this->youtubeOf($multimediaObject)->getCaptions()[0]->getMaterialId());
    }

    public function testTheOlderMaterialIsUploadedIfTheLatestIsHidden(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $older = $this->material($multimediaObject);
        $this->material($multimediaObject)->setHide(true);
        $this->dm->flush();
        $this->willAcceptACaption('caption1');

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame($older->getId(), $this->youtubeOf($multimediaObject)->getCaptions()[0]->getMaterialId());
    }

    public function testDifferentLanguagesCoexist(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption($this->material($multimediaObject, 'es')->getId(), 'caption1', 'es'));
        $this->dm->flush();
        $this->material($multimediaObject, 'en');
        $this->willAcceptACaption('caption2');

        $this->runCommand('pumukit:youtube:caption:upload');
        $this->runCommand('pumukit:youtube:caption:delete');

        $this->assertSame(0, $this->api->callsTo('DELETE', '/captions'));
        $this->assertCount(2, $this->youtubeOf($multimediaObject)->getCaptions());
    }

    public function testAFileReplacedInTheSameMaterialIsUploadedAgain(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $material = $this->material($multimediaObject);
        $this->willAcceptACaption('caption1');
        $this->runCommand('pumukit:youtube:caption:upload');
        file_put_contents($material->getPath(), "WEBVTT\n\nnew content");
        $this->api->willAnswerEmpty();
        $this->willAcceptACaption('caption2');

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(1, $this->api->callsTo('DELETE', '/captions'));
        $captions = $this->youtubeOf($multimediaObject)->getCaptions();
        $this->assertCount(1, $captions);
        $this->assertSame('caption2', $captions[0]->getCaptionId());
    }

    public function testAnUnchangedFileIsNotUploadedAgain(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $this->youtubeDocument($multimediaObject);
        $this->material($multimediaObject);
        $this->willAcceptACaption('caption1');
        $this->runCommand('pumukit:youtube:caption:upload');

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(1, $this->api->callsTo('POST', '/captions'));
    }

    public function testACaptionUploadedBeforeNotesItsFileWithoutCallingYoutube(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $material = $this->material($multimediaObject);
        $youtube->addCaption($this->caption($material->getId(), 'caption1', 'es'));
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(0, $this->api->calls());
        $this->assertSame(sha1_file($material->getPath()), $this->youtubeOf($multimediaObject)->getCaptions()[0]->getFileHash());
    }

    public function testTwoCaptionsOfALanguageAlreadyOnYoutubeKeepOnlyTheLatest(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $older = $this->material($multimediaObject);
        $latest = $this->material($multimediaObject);
        $youtube->addCaption($this->caption($older->getId(), 'caption1', 'es'));
        $youtube->addCaption($this->caption($latest->getId(), 'caption2', 'es'));
        $this->dm->flush();
        $this->api->willAnswerEmpty();

        $this->runCommand('pumukit:youtube:caption:upload');
        $this->runCommand('pumukit:youtube:caption:delete');

        $this->assertSame(1, $this->api->callsTo('DELETE', '/captions'));
        $this->assertSame(0, $this->api->callsTo('POST', '/captions'));
        $captions = $this->youtubeOf($multimediaObject)->getCaptions();
        $this->assertCount(1, $captions);
        $this->assertSame('caption2', $captions[0]->getCaptionId());
    }

    public function testACaptionStaysUntilItsReplacementIsOnYoutube(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption($this->material($multimediaObject)->getId(), 'caption1', 'es'));
        $this->material($multimediaObject);
        $this->dm->flush();

        $this->runCommand('pumukit:youtube:caption:delete');

        $this->assertSame(0, $this->api->calls());
        $this->assertCount(1, $this->youtubeOf($multimediaObject)->getCaptions());
    }

    public function testACaptionThatCannotBeDeletedIsNotReplaced(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption($this->material($multimediaObject)->getId(), 'caption1', 'es'));
        $this->material($multimediaObject);
        $this->dm->flush();
        $this->api->willFail('forbidden', 403);

        $this->runCommand('pumukit:youtube:caption:upload');

        $this->assertSame(0, $this->api->callsTo('POST', '/captions'));
        $stored = $this->youtubeOf($multimediaObject);
        $this->assertSame('caption1', $stored->getCaptions()[0]->getCaptionId());
        $this->assertSame('forbidden', $stored->getCaptionUpdateError()->id());
    }

    public function testACaptionAlreadyGoneIsReplacedWithoutFailing(): void
    {
        $multimediaObject = $this->videoForYoutube();
        $youtube = $this->youtubeDocument($multimediaObject);
        $youtube->addCaption($this->caption($this->material($multimediaObject)->getId(), 'caption1', 'es'));
        $this->material($multimediaObject);
        $this->dm->flush();
        $this->api->willFail('captionNotFound', 404);
        $this->willAcceptACaption('caption2');

        $this->runCommand('pumukit:youtube:caption:upload');

        $stored = $this->youtubeOf($multimediaObject);
        $this->assertCount(1, $stored->getCaptions());
        $this->assertSame('caption2', $stored->getCaptions()[0]->getCaptionId());
        $this->assertNull($stored->getCaptionUpdateError());
    }

    private function willAcceptACaption(string $id): void
    {
        $this->api->willAnswer(['kind' => 'youtube#caption', 'id' => $id, 'snippet' => ['name' => 'Caption', 'language' => 'es', 'lastUpdated' => '2026-01-01T10:00:00Z', 'isDraft' => false]]);
    }

    private function caption(string $materialId, string $captionId, string $language = 'en'): Caption
    {
        $caption = new Caption();
        $caption->setLanguage($language);
        $caption->setMaterialId($materialId);
        $caption->setCaptionId($captionId);
        $caption->setName('Caption');
        $caption->setLastUpdated(new \DateTime());

        return $caption;
    }
}
