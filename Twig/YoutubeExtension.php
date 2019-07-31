<?php

namespace Pumukit\YoutubeBundle\Twig;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\EmbeddedBroadcast;
use Pumukit\SchemaBundle\Document\EmbeddedTag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\CaptionService;
use Pumukit\SchemaBundle\Services\MultimediaObjectDurationService;
use Pumukit\SchemaBundle\Services\PicService;
use Pumukit\WebTVBundle\Services\LinkService;
use Symfony\Component\Routing\RequestContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
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
     * @param $tagId
     *
     * @return mixed
     * @throws \Doctrine\ODM\MongoDB\LockException
     * @throws \Doctrine\ODM\MongoDB\Mapping\MappingException
     */
    public function getPlaylistName($tagId)
    {
        $tag = $this->documentManager->getRepository(Tag::class)->find($tagId);

        return $tag->getTitle();
    }
}
