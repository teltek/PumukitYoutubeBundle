<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use Pumukit\YoutubeBundle\Tests\Support\YoutubeTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;

/**
 * @internal
 *
 * @coversNothing
 */
class CommandsTest extends YoutubeTestCase
{
    #[DataProvider('commands')]
    public function testTheCommandKeepsItsName(string $name): void
    {
        $this->assertTrue((new Application($this->client->getKernel()))->has($name));
    }

    public static function commands(): iterable
    {
        foreach ([
            'account:create', 'account:test', 'caption:delete', 'caption:list', 'caption:upload', 'download:videos:from:channel',
            'errors:cleanup', 'import:add:jobs', 'import:delete:externalplayer', 'import:externalplayer', 'import:lives:from:channel',
            'import:playlist:from:channel', 'import:video', 'import:videos:from:channel', 'import:videos:on:playlist', 'init:pubchannel',
            'metadata:error:notification', 'migration:schema', 'migration:v5:schema', 'operations:preview', 'playlist:error:notification',
            'playlist:sync', 'playlist:update', 'tags:reconcile', 'video:delete', 'video:error:notification', 'video:link',
            'video:review:assign', 'video:review:list', 'video:update:metadata', 'video:update:pendingstatus', 'video:update:status', 'video:upload',
        ] as $name) {
            yield $name => ['pumukit:youtube:'.$name];
        }
    }
}
