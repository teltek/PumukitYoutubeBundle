<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Delete;

use Pumukit\YoutubeBundle\AccountHexagonal\Domain\ValueObject\AccountId;

final class DeleteAccountValidator
{
    public function validate(DeleteAccountRequest $request): void
    {
        // Validate account ID
        new AccountId($request->getAccountId());
    }
}
