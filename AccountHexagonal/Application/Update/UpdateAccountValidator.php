<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Update;

use Pumukit\YoutubeBundle\AccountHexagonal\Domain\ValueObject\AccountId;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\ValueObject\AccountLogin;

final class UpdateAccountValidator
{
    public function validate(UpdateAccountRequest $request): void
    {
        // Validate account ID
        new AccountId($request->getAccountId());

        // Validate login if provided
        if ($request->getLogin() !== null) {
            new AccountLogin($request->getLogin());
        }

        // Validate i18n title if provided
        if ($request->getI18nTitle() !== null) {
            if (empty($request->getI18nTitle())) {
                throw new \InvalidArgumentException('Account title cannot be empty');
            }

            // Validate at least one title is provided
            $hasTitle = false;
            foreach ($request->getI18nTitle() as $title) {
                if (!empty($title)) {
                    $hasTitle = true;
                    break;
                }
            }

            if (!$hasTitle) {
                throw new \InvalidArgumentException('At least one translated title must be provided');
            }
        }
    }
}
