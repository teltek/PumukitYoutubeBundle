<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\QuotaHexagonal\Application\Waiting;

use DateTimeImmutable;

/**
 * Mensaje que envuelve otro mensaje cuando la quota está excedida.
 * Se almacena en la cola "quota.waiting" hasta que la quota se resetee.
 */
final class WaitingMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $accountId,
        private readonly object $originalMessage,
        private readonly int $quotaCost,
        private readonly string $operation,
        private readonly ?int $priority = 0
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getOriginalMessage(): object
    {
        return $this->originalMessage;
    }

    public function getQuotaCost(): int
    {
        return $this->quotaCost;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getPriority(): int
    {
        return $this->priority ?? 0;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Obtiene el nombre de la clase del mensaje original
     */
    public function getOriginalMessageClass(): string
    {
        return get_class($this->originalMessage);
    }
}
