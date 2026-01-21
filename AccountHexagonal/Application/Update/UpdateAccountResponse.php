<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Update;

use Pumukit\SchemaBundle\Document\Tag;

final class UpdateAccountResponse
{
    private Tag $account;

    public function __construct(Tag $account)
    {
        $this->account = $account;
    }

    public function getAccount(): Tag
    {
        return $this->account;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->account->getId(),
            'login' => $this->account->getProperty('login'),
            'title' => $this->account->getI18nTitle(),
            'cod' => $this->account->getCod(),
        ];
    }
}
