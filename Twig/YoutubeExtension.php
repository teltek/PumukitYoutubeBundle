<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Twig;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
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
            new TwigFunction('metadata_update_errors_count', [$this, 'getMetadataUpdateErrorsCount']),
            new TwigFunction('playlists_update_errors_count', [$this, 'getPlaylistsUpdateErrorsCount']),
            new TwigFunction('captions_update_errors_count', [$this, 'getCaptionsUpdateErrorsCount']),
            new TwigFunction('published_videos', [$this, 'getPublishedVideos']),
            new TwigFunction('processing_videos', [$this, 'getProcessingVideos']),
            new TwigFunction('removed_videos', [$this, 'getRemovedVideos']),
            new TwigFunction('to_delete_videos', [$this, 'getToDeleteVideos']),
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
        return count($this->youtubeStatsService->getByError());
    }

    public function getMetadataUpdateErrorsCount(): int
    {
        return count($this->youtubeStatsService->getByMetadataUpdateError());
    }

    public function getPlaylistsUpdateErrorsCount(): int
    {
        return count($this->youtubeStatsService->getByPlaylistUpdateError());
    }

    public function getCaptionsUpdateErrorsCount(): int
    {
        return count($this->youtubeStatsService->getByCaptionUpdateError());
    }

    public function getPublishedVideos(): array
    {
        return $this->youtubeStatsService->getPublishedVideos();
    }

    public function getProcessingVideos(): array
    {
        return $this->youtubeStatsService->getProcessingVideos();
    }

    public function getRemovedVideos(): array
    {
        return $this->youtubeStatsService->getRemovedVideos();
    }

    public function getToDeleteVideos(): array
    {
        return $this->youtubeStatsService->getToDeleteVideos();
    }
}
