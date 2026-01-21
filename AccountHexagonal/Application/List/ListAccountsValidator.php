<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\List;

final class ListAccountsValidator
{
    public function validate(ListAccountsRequest $request): void
    {
        // No validation needed for listing
    }
}
