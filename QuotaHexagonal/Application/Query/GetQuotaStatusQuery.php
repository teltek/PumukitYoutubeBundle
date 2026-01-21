<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\QuotaHexagonal\Application\Query;

final class GetQuotaStatusQuery
{
    public function __construct(
        private readonly ?string $accountId = null
    ) {
    }

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }
}
