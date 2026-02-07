<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;

final class CreateAccountResponse
{
    private YoutubeAccount $account;

    public function __construct(YoutubeAccount $account)
    {
        $this->account = $account;
    }

    public function getAccount(): YoutubeAccount
    {
        return $this->account;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->account->getId(),
            'accountName' => $this->account->getAccountName(),
            'channelId' => $this->account->getChannelId(),
            'credentialsPath' => $this->account->getCredentialsPath(),
        ];
    }
}
