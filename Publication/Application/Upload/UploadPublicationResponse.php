<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Application\Upload;

use Pumukit\YoutubeBundle\Document\Youtube;

/**
 * Response containing the created or updated Publication (Youtube document).
 */
final class UploadPublicationResponse
{
    public function __construct(
        public readonly Youtube $publication
    ) {}
}
