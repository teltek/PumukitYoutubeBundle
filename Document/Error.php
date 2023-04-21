<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument()
 */
class Error
{
    /**
     * @MongoDB\Field(type="string")
     */
    private $id;

    /**
     * @MongoDB\Field(type="string")
     */
    private $message;

    /**
     * @MongoDB\Field(type="date")
     */
    private $date;

    /**
     * @MongoDB\Field(type="raw")
     */
    private $raw;

    private function __construct(
        string $id,
        string $message,
        \DateTimeInterface $date,
        $raw
    ) {
        $this->id = $id;
        $this->message = $message;
        $this->date = $date;
        $this->raw = $raw;
    }

    public static function create(
        string $id,
        string $message,
        \DateTimeInterface $date,
        $raw
    ): self {
        return new self($id, $message, $date, $raw);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function date(): \DateTimeInterface
    {
        return $this->date;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function raw()
    {
        return $this->raw;
    }
}
