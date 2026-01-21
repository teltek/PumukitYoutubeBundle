<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Sync;

use Pumukit\YoutubeBundle\AccountHexagonal\Domain\ValueObject\AccountId;

final class SyncAccountValidator
{
    public function validate(SyncAccountRequest $request): void
    {
        // Validate account ID
        new AccountId($request->getAccountId());

        // Validate channel ID
        if (empty($request->getChannelId())) {
            throw new \InvalidArgumentException('Channel ID cannot be empty');
        }
    }
}
