<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Upload;

final class UploadCaptionRequest
{
    private string $multimediaObjectId;
    private string $language;

    public function __construct(string $multimediaObjectId, string $language)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->language = $language;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }
}
