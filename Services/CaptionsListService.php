<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Google\Service\YouTube\CaptionListResponse;
use Pumukit\SchemaBundle\Document\Tag;

class CaptionsListService extends GoogleAccountService
{
    public function findAll(Tag $account, string $videoId): CaptionListResponse
    {
        return $this->list($account, $videoId);
    }

    private function list(Tag $account, string $videoId): CaptionListResponse
    {
        $service = $this->googleServiceFromAccount($account);

        return $service->captions->listCaptions('snippet', $videoId);
    }
}
