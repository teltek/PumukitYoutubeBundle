<?php

namespace Pumukit\YoutubeBundle\Twig;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Class PumukitExtension.
 */
class YoutubeExtension extends AbstractExtension
{
    /**
     * @var DocumentManager
     */
    private $documentManager;

    /**
     * PumukitExtension constructor.
     *
     * @param DocumentManager $documentManager
     */
    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    /**
     * @return array
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('playlist_name', [$this, 'getPlaylistName']),
        ];
    }

    /**
     * @param string $youtubePlaylistHash
     *
     * @return string
     */
    public function getPlaylistName($youtubePlaylistHash)
    {
        $tag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.youtube' => $youtubePlaylistHash,
        ]);

        if (!$tag) {
            return '';
        }

        return $tag->getTitle();
    }
}
