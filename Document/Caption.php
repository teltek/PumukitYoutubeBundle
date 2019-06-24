<?php

namespace Pumukit\YoutubeBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * Pumukit\YoutubeBundle\Document\Caption.
 *
 * @MongoDB\EmbeddedDocument
 */
class Caption
{
    /**
     * @var int
     *
     * @MongoDB\Id
     */
    private $id;

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $materialId;

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $captionId;

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $language = 'en';

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $name;

    /**
     * @var \DateTime
     *
     * @MongoDB\Field(type="date")
     */
    private $lastUpdated;

    /**
     * @var bool
     *
     * @MongoDB\Field(type="boolean")
     */
    private $isDraft = false;

    /**
     * Get id.
     *
     * @param string
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set materialId.
     *
     * @param string $materialId
     */
    public function setMaterialId($materialId)
    {
        $this->materialId = $materialId;
    }

    /**
     * Get materialId.
     *
     * @return string
     */
    public function getMaterialId()
    {
        return $this->materialId;
    }

    /**
     * Set captionId.
     *
     * @param string $captionId
     */
    public function setCaptionId($captionId)
    {
        $this->captionId = $captionId;
    }

    /**
     * Get captionId.
     *
     * @return string
     */
    public function getCaptionId()
    {
        return $this->captionId;
    }

    /**
     * Set language.
     *
     * @param string $language
     */
    public function setLanguage($language)
    {
        $this->language = $language;
    }

    /**
     * Get language.
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Set name.
     *
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set lastUpdated.
     *
     * @param string $lastUpdated
     */
    public function setLastUpdated($lastUpdated)
    {
        $this->lastUpdated = $lastUpdated;
    }

    /**
     * Get lastUpdated.
     *
     * @return string
     */
    public function getLastUpdated()
    {
        return $this->lastUpdated;
    }

    /**
     * Set isDraft.
     *
     * @param string $isDraft
     */
    public function setIsDraft($isDraft)
    {
        $this->isDraft = $isDraft;
    }

    /**
     * Get isDraft.
     *
     * @return string
     */
    public function getIsDraft()
    {
        return $this->isDraft;
    }

    /**
     * Is Draft.
     *
     * @return string
     */
    public function isDraft()
    {
        return $this->isDraft;
    }
}
