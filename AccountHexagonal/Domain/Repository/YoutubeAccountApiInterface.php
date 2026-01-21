<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository;

use Pumukit\SchemaBundle\Document\Tag;

interface YoutubeAccountApiInterface
{
    public function verifyConnection(Tag $account): bool;

    public function getPlaylistCount(Tag $account, string $channelId): int;

    public function createAccessToken(string $login, string $authorizationCode): array;

    public function refreshAccessToken(Tag $account): array;
}
