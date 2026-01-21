<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Upload;

final class UploadCaptionResponse
{
    private string $multimediaObjectId;
    private string $language;
    private bool $success;

    public function __construct(string $multimediaObjectId, string $language, bool $success)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->language = $language;
        $this->success = $success;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'multimediaObjectId' => $this->multimediaObjectId,
            'language' => $this->language,
            'success' => $this->success,
        ];
    }
}
