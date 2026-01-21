<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\UpdatePublication;

use Pumukit\YoutubeBundle\VideoHexagonal\Domain\ValueObject\VideoPrivacy;

final class UpdatePublicationValidator
{
    public function validate(UpdatePublicationRequest $request): void
    {
        if (empty($request->getMultimediaObjectId())) {
            throw new \InvalidArgumentException('MultimediaObject ID cannot be empty');
        }

        // Validate privacy value
        new VideoPrivacy($request->getPrivacy());
    }
}
