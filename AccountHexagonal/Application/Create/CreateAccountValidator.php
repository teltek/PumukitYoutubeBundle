<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

use Pumukit\YoutubeBundle\AccountHexagonal\Domain\ValueObject\AccountLogin;

final class CreateAccountValidator
{
    public function validate(CreateAccountRequest $request): void
    {
        // Validate login format
        new AccountLogin($request->getLogin());

        // Validate i18n title is not empty
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
