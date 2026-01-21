<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Infrastructure\ExternalService;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Repository\YoutubeCaptionApiInterface;
use Pumukit\YoutubeBundle\Services\CaptionsInsertService;
use Pumukit\YoutubeBundle\Services\CaptionsDeleteService;
use Pumukit\YoutubeBundle\Services\CaptionsListService;

final class GoogleYoutubeCaptionApi implements YoutubeCaptionApiInterface
{
    private CaptionsInsertService $captionsInsertService;
    private CaptionsDeleteService $captionsDeleteService;
    private CaptionsListService $captionsListService;

    public function __construct(
        CaptionsInsertService $captionsInsertService,
        CaptionsDeleteService $captionsDeleteService,
        CaptionsListService $captionsListService
    ) {
        $this->captionsInsertService = $captionsInsertService;
        $this->captionsDeleteService = $captionsDeleteService;
        $this->captionsListService = $captionsListService;
    }

    public function upload(MultimediaObject $multimediaObject, string $language): bool
    {
        // TODO: Implement proper caption upload using captionsInsertService
        // For now, return true as placeholder
        return true;
    }

    public function delete(string $youtubeId, string $captionId): bool
    {
        return $this->captionsDeleteService->deleteCaption($youtubeId, $captionId);
    }

    public function list(string $youtubeId): array
    {
        return $this->captionsListService->listCaptions($youtubeId);
    }
}
