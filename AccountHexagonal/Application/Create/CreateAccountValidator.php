<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

final class CreateAccountValidator
{
    public function validate(CreateAccountRequest $request): void
    {
        // Validate name is not empty
        if (empty(trim($request->getName()))) {
            throw new \InvalidArgumentException('Account name cannot be empty');
        }

        // Validate name format (alphanumeric and underscores only)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $request->getName())) {
            throw new \InvalidArgumentException('Account name can only contain letters, numbers, underscores and hyphens');
        }

        // Validate credentials path is not empty
        if (empty(trim($request->getCredentialsPath()))) {
            throw new \InvalidArgumentException('Credentials path cannot be empty');
        }

        // Validate credentials file exists
        if (!file_exists($request->getCredentialsPath())) {
            throw new \InvalidArgumentException(sprintf(
                'Credentials file not found: %s',
                $request->getCredentialsPath()
            ));
        }

        // Validate credentials file is valid JSON
        $contents = file_get_contents($request->getCredentialsPath());
        $json = json_decode($contents, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(sprintf(
                'Credentials file is not valid JSON: %s',
                json_last_error_msg()
            ));
        }

        // Validate it contains required Google OAuth fields
        if (!isset($json['installed']) && !isset($json['web'])) {
            throw new \InvalidArgumentException(
                'Credentials file must be a valid Google OAuth 2.0 client secret file (missing "installed" or "web" key)'
            );
        }
    }
}
