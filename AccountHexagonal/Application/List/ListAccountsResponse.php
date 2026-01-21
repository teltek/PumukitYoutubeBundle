<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\List;

final class ListAccountsResponse
{
    private array $accounts;

    public function __construct(array $accounts)
    {
        $this->accounts = $accounts;
    }

    public function getAccounts(): array
    {
        return $this->accounts;
    }

    public function toArray(): array
    {
        return array_map(function ($account) {
            return [
                'id' => $account->getId(),
                'login' => $account->getProperty('login'),
                'title' => $account->getI18nTitle(),
                'cod' => $account->getCod(),
                'children' => count($account->getChildren()), // Number of playlists
            ];
        }, $this->accounts);
    }
}
