<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Application\Upload;

/**
 * Represents a request to upload or update a PuMuKIT MultimediaObject on YouTube.
 */
final class UploadPublicationRequest
{
    public function __construct(
        public readonly string $multimediaObjectId,
        public readonly string $youtubeAccountId,
        public readonly array $playlists = []
    ) {
        UploadPublicationValidator::validate($this);
    }
}
