<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Support;

use Google\Service\YouTube;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Services\CaptionsListService;

final class FakeCaptionsListService extends CaptionsListService
{
    public function __construct(private readonly FakeYoutubeApi $api) {}

    public function googleServiceFromAccount(Tag $youtubeAccount): YouTube
    {
        return $this->api->service();
    }
}
