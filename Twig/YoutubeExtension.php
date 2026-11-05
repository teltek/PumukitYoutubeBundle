<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Twig;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubeStatsService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class YoutubeExtension extends AbstractExtension
{
    private $documentManager;
    private $youtubeStatsService;

    public function __construct(DocumentManager $documentManager, YoutubeStatsService $youtubeStatsService)
    {
        $this->documentManager = $documentManager;
        $this->youtubeStatsService = $youtubeStatsService;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('playlist_name', [$this, 'getPlaylistName']),
            new TwigFunction('status_text', [$this, 'getStatusText']),
            new TwigFunction('multimedia_object_title', [$this, 'getMultimediaObjectTitle']),
            new TwigFunction('upload_errors_count', [$this, 'getErrorsCount']),
            new TwigFunction('needing_action_count', [$this, 'getNeedingActionCount']),
            new TwigFunction('metadata_update_errors_count', [$this, 'getMetadataUpdateErrorsCount']),
            new TwigFunction('playlists_update_errors_count', [$this, 'getPlaylistsUpdateErrorsCount']),
            new TwigFunction('captions_update_errors_count', [$this, 'getCaptionsUpdateErrorsCount']),
            new TwigFunction('published_videos_count', [$this, 'getPublishedVideosCount']),
            new TwigFunction('processing_videos_count', [$this, 'getProcessingVideosCount']),
            new TwigFunction('removed_videos_count', [$this, 'getRemovedVideosCount']),
            new TwigFunction('to_delete_videos_count', [$this, 'getToDeleteVideosCount']),
        ];
    }

    public function getPlaylistName(string $youtubePlaylistHash): string
    {
        $tag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.youtube' => $youtubePlaylistHash,
        ]);

        if (!$tag) {
            return '';
        }

        return $tag->getTitle();
    }

    public function getStatusText(int $status): string
    {
        return $this->youtubeStatsService->getTextByStatus($status);
    }

    public function getMultimediaObjectTitle(string $id): ?string
    {
        $object = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy(['_id' => new ObjectId($id)]);
        if (!$object) {
            return null;
        }

        return $object->getTitle();
    }

    public function getErrorsCount(): int
    {
        return $this->youtubeStatsService->countWithError('error');
    }

    public function getNeedingActionCount(): int
    {
        return $this->youtubeStatsService->countNeedingAction();
    }

    public function getMetadataUpdateErrorsCount(): int
    {
        return $this->youtubeStatsService->countWithError('metadataUpdateError');
    }

    public function getPlaylistsUpdateErrorsCount(): int
    {
        return $this->youtubeStatsService->countWithError('playlistUpdateError');
    }

    public function getCaptionsUpdateErrorsCount(): int
    {
        return $this->youtubeStatsService->countWithError('captionUpdateError');
    }

    public function getPublishedVideosCount(): int
    {
        return $this->youtubeStatsService->countByStatus([Youtube::STATUS_PUBLISHED]);
    }

    public function getProcessingVideosCount(): int
    {
        return $this->youtubeStatsService->countByStatus([Youtube::STATUS_DEFAULT, Youtube::STATUS_UPLOADING, Youtube::STATUS_PROCESSING]);
    }

    public function getRemovedVideosCount(): int
    {
        return $this->youtubeStatsService->countByStatus([Youtube::STATUS_REMOVED]);
    }

    public function getToDeleteVideosCount(): int
    {
        return $this->youtubeStatsService->countByStatus([Youtube::STATUS_TO_DELETE]);
    }
}
