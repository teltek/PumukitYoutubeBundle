<?php

namespace Pumukit\YoutubeBundle\Twig;

use Doctrine\ODM\MongoDB\DocumentManager;
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
        $object = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy(['_id' => new \MongoId($id)]);
        if (!$object) {
            return null;
        }

        return $object->getTitle();
    }
}
