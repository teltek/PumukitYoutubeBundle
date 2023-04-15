<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Tag;

class CaptionsListService extends GoogleAccountService
{
    public function findAll(Tag $account, string $videoId): \Google\Service\YouTube\CaptionListResponse
    {
        return $this->list($account, $videoId);
    }
    private function list(Tag $account, string $videoId): \Google\Service\YouTube\CaptionListResponse
    {
        $service = $this->googleServiceFromAccount($account);

        return $service->captions->listCaptions('snippet', $videoId);
    }

}
