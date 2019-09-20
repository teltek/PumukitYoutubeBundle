<?php

namespace Pumukit\YoutubeBundle\Twig;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class YoutubeExtension extends AbstractExtension
{
    /**
     * @var DocumentManager
     */
    private $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('playlist_name', [$this, 'getPlaylistName']),
            new TwigFunction('status_icon', [$this, 'getStatusIcon']),
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

    public function getStatusIcon(int $status): string
    {
        return 'mdi-action-accessibility';
    }
}
