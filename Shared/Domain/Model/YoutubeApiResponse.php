<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Domain\Model;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use MongoDB\BSON\ObjectId;

/**
 * @MongoDB\Document(collection="youtube_api_responses")
 * @MongoDB\HasLifecycleCallbacks
 */
class YoutubeApiResponse
{
    /**
     * @MongoDB\Id
     */
    private ?string $id = null;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private string $youtubeAccountId;

    /**
     * @MongoDB\Field(type="string")
     */
    private string $operation;

    /**
     * @MongoDB\Field(type="int")
     */
    private int $quotaCost;

    /**
     * @MongoDB\Field(type="bool")
     */
    private bool $success;

    /**
     * @MongoDB\Field(type="int")
     */
    private ?int $httpStatusCode;

    /**
     * @MongoDB\Field(type="hash")
     */
    private array $request;

    /**
     * @MongoDB\Field(type="hash")
     */
    private array $response;

    /**
     * @MongoDB\Field(type="string")
     */
    private ?string $errorMessage = null;

    /**
     * @MongoDB\Field(type="hash")
     */
    private ?array $errorDetails = null;

    /**
     * @MongoDB\Field(type="date")
     */
    private \DateTimeInterface $createdAt;

    public function __construct(
        string $youtubeAccountId,
        string $operation,
        int $quotaCost,
        array $request = []
    ) {
        $this->youtubeAccountId = $youtubeAccountId;
        $this->operation = $operation;
        $this->quotaCost = $quotaCost;
        $this->request = $request;
        $this->success = false;
        $this->response = [];
        $this->httpStatusCode = null;
        $this->createdAt = new \DateTime();
    }

    /**
     * @MongoDB\PostLoad
     */
    public function ensurePropertiesInitialized(): void
    {
        // Ensure httpStatusCode is initialized for documents created before this property was added
        if (!isset($this->httpStatusCode)) {
            $this->httpStatusCode = null;
        }
        
        // Ensure errorDetails is initialized for older documents
        if (!isset($this->errorDetails)) {
            $this->errorDetails = null;
        }
    }

    public function markAsSuccess(array $response, int $httpStatusCode = 200): void
    {
        $this->success = true;
        $this->response = $response;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function markAsFailure(string $errorMessage, array $errorDetails = [], ?int $httpStatusCode = null): void
    {
        $this->success = false;
        $this->errorMessage = $errorMessage;
        $this->errorDetails = $errorDetails;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getYoutubeAccountId(): string
    {
        return $this->youtubeAccountId;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getQuotaCost(): int
    {
        return $this->quotaCost;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    public function getRequest(): array
    {
        return $this->request;
    }

    public function getResponse(): array
    {
        return $this->response;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorDetails(): ?array
    {
        return $this->errorDetails;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getResponseAsJson(): string
    {
        return json_encode($this->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function getRequestAsJson(): string
    {
        return json_encode($this->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function getErrorDetailsAsJson(): ?string
    {
        if ($this->errorDetails === null) {
            return null;
        }

        return json_encode($this->errorDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
