<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Upload;

use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\ValueObject\CaptionLanguage;

final class UploadCaptionValidator
{
    public function validate(UploadCaptionRequest $request): void
    {
        if (empty($request->getMultimediaObjectId())) {
            throw new \InvalidArgumentException('MultimediaObject ID cannot be empty');
        }

        // Validate language format
        new CaptionLanguage($request->getLanguage());
    }
}
