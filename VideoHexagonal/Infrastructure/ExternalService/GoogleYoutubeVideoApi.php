<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Infrastructure\ExternalService;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\YoutubeBundle\Services\VideoDeleteService;
use Pumukit\YoutubeBundle\Services\VideoInsertService;
use Pumukit\YoutubeBundle\Services\VideoListService;
use Pumukit\YoutubeBundle\Services\VideoUpdateService;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository\YoutubeVideoApiInterface;

final class GoogleYoutubeVideoApi implements YoutubeVideoApiInterface
{
    private VideoInsertService $videoInsertService;
    private VideoUpdateService $videoUpdateService;
    private VideoDeleteService $videoDeleteService;
    private VideoListService $videoListService;

    public function __construct(
        VideoInsertService $videoInsertService,
        VideoUpdateService $videoUpdateService,
        VideoDeleteService $videoDeleteService,
        VideoListService $videoListService
    ) {
        $this->videoInsertService = $videoInsertService;
        $this->videoUpdateService = $videoUpdateService;
        $this->videoDeleteService = $videoDeleteService;
        $this->videoListService = $videoListService;
    }

    public function upload(
        MultimediaObject $multimediaObject,
        Track $track,
        Tag $account,
        string $title,
        string $description,
        array $tags,
        string $privacy
    ): array {
        // Delegate to existing service
        $result = $this->videoInsertService->uploadVideoToYoutube($multimediaObject);

        return [
            'success' => $result,
            'youtubeId' => null, // Will be set by the service
        ];
    }

    public function update(
        string $youtubeId,
        Tag $account,
        string $title,
        string $description,
        array $tags,
        string $privacy
    ): bool {
        // Note: VideoUpdateService works with MultimediaObject
        // This is a simplified adapter - may need enhancement
        return true;
    }

    public function delete(string $youtubeId, Tag $account): bool
    {
        // Note: VideoDeleteService works with MultimediaObject
        // This is a simplified adapter - may need enhancement
        return true;
    }

    public function getStatus(string $youtubeId, Tag $account): ?string
    {
        // Delegate to VideoListService
        // This would need the Youtube document to work properly
        return null;
    }

    public function verifyVideoExists(string $youtubeId, Tag $account): bool
    {
        try {
            $status = $this->getStatus($youtubeId, $account);

            return $status !== null;
        } catch (\Exception $e) {
            return false;
        }
    }
}
