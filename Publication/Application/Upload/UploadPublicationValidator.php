<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Application\Upload;

use Pumukit\YoutubeBundle\Repository\YoutubeRepository;
use Pumukit\SchemaBundle\Services\MultimediaObjectService;

/**
 * Validates that a YouTube upload request can proceed.
 * 
 * Validation rules:
 * 1. MultimediaObjectId is not empty
 * 2. YoutubeAccount exists
 * 3. YoutubeAccount.paused === false
 * 4. Playlists belong to the YoutubeAccount
 * 5. MultimediaObject contains required video data
 */
final class UploadPublicationValidator
{
    public function __construct(
        private YoutubeRepository $youtubeRepository,
        private MultimediaObjectService $multimediaObjectService
    ) {}

    public static function validate(UploadPublicationRequest $request): void
    {
        // Basic field validation
        if (empty($request->multimediaObjectId)) {
            throw new \InvalidArgumentException('MultimediaObject ID cannot be empty');
        }

        if (empty($request->youtubeAccountId)) {
            throw new \InvalidArgumentException('YouTube Account ID cannot be empty');
        }
    }

    /**
     * Full validation with repository checks.
     * Should be called by the use case handler.
     */
    public function validateForUpload(UploadPublicationRequest $request): void
    {
        self::validate($request);

        // TODO: Implement repository-based validations:
        // - Check if YoutubeAccount exists
        // - Check if YoutubeAccount is not paused
        // - Validate playlists belong to the account
        // - Verify MultimediaObject has required data (duration, title, file ready)
    }
}
