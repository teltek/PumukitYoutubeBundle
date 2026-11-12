<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use MongoDB\BSON\ObjectId;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class CleanupErrorsCommandTest extends YoutubeTestCase
{
    public function testTheDryRunCountsOnlyTheErrorFieldsStoredAsNull(): void
    {
        $withNull = $this->youtubeDocument($this->videoForYoutube());
        $withoutField = $this->youtubeDocument($this->videoForYoutube());
        $this->rawUpdate($withNull, ['$set' => ['error' => null]]);
        $this->rawUpdate($withoutField, ['$unset' => ['error' => '']]);

        $tester = $this->runCommand('pumukit:youtube:errors:cleanup', ['--field' => 'error']);

        $this->assertStringContainsString('1 residue documents', $tester->getDisplay());
    }

    public function testApplyRemovesTheErrorFieldsStoredAsNull(): void
    {
        $withNull = $this->youtubeDocument($this->videoForYoutube());
        $this->rawUpdate($withNull, ['$set' => ['error' => null]]);

        $tester = $this->runCommand('pumukit:youtube:errors:cleanup', ['--field' => 'error', '--apply' => true]);

        $this->assertStringContainsString('1 residue fields', $tester->getDisplay());
        $stored = $this->dm->getDocumentCollection(Youtube::class)->findOne(['_id' => new ObjectId($withNull->getId())]);
        $this->assertArrayNotHasKey('error', (array) $stored);
    }

    private function rawUpdate(Youtube $youtube, array $update): void
    {
        $this->dm->getDocumentCollection(Youtube::class)->updateOne(['_id' => new ObjectId($youtube->getId())], $update);
    }
}
