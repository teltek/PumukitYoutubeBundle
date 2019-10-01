<?php

namespace Pumukit\YoutubeBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument
 */
class Caption
{
    /**
     * @MongoDB\Id
     */
    private $id;

    /**
     * @MongoDB\Field(type="string")
     */
    private $materialId;

    /**
     * @MongoDB\Field(type="string")
     */
    private $captionId;

    /**
     * @MongoDB\Field(type="string")
     */
    private $language = 'en';

    /**
     * @MongoDB\Field(type="string")
     */
    private $name;

    /**
     * @MongoDB\Field(type="date")
     */
    private $lastUpdated;

    /**
     * @MongoDB\Field(type="boolean")
     */
    private $isDraft = false;

    public function getId()
    {
        return $this->id;
    }

    public function setMaterialId(string $materialId): void
    {
        $this->materialId = $materialId;
    }

    public function getMaterialId(): string
    {
        return $this->materialId;
    }

    public function setCaptionId(string $captionId): void
    {
        $this->captionId = $captionId;
    }

    public function getCaptionId(): string
    {
        return $this->captionId;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setLastUpdated(\DateTime $lastUpdated): void
    {
        $this->lastUpdated = $lastUpdated;
    }

    public function getLastUpdated(): \DateTime
    {
        return $this->lastUpdated;
    }

    public function setIsDraft(bool $isDraft): void
    {
        $this->isDraft = $isDraft;
    }

    public function getIsDraft(): bool
    {
        return $this->isDraft();
    }

    public function isDraft(): bool
    {
        return $this->isDraft;
    }
}
