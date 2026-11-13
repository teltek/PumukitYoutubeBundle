<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use Pumukit\CoreBundle\Tests\PumukitTestCase;
use Pumukit\SchemaBundle\Document\Tag;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 *
 * @coversNothing
 */
class YoutubeInitTagsCommandTest extends PumukitTestCase
{
    public function testItCreatesTheTagsOnlyOnce(): void
    {
        $this->createTag('ROOT');
        $this->createTag('PUBCHANNELS');

        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        $this->assertStringContainsString('The tag PUCHYOUTUBE is created.', $tester->getDisplay());
        $this->assertStringContainsString('The tag YOUTUBE is created.', $tester->getDisplay());

        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        $this->assertStringContainsString('already exists', $tester->getDisplay());

        $this->dm->clear();
        $this->assertCount(1, $this->dm->getRepository(Tag::class)->findBy(['cod' => 'PUCHYOUTUBE']));
        $this->assertCount(1, $this->dm->getRepository(Tag::class)->findBy(['cod' => 'YOUTUBE']));
    }

    public function testItFailsWithoutTheParentTag(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('run pumukit:init:repo first', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('pumukit:youtube:init:pubchannel'));
    }

    private function createTag(string $code): Tag
    {
        $tag = new Tag();
        $tag->setCod($code);
        $this->dm->persist($tag);
        $this->dm->flush();

        return $tag;
    }
}
