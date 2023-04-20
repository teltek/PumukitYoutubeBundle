<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Tag;

class PlaylistDeleteService extends GooglePlaylistService
{
    private $googleAccountService;

    public function __construct(GoogleAccountService $googleAccountService) {
        $this->googleAccountService = $googleAccountService;
    }

    public function deleteOnePlaylist(Tag $youtubeAccount, string $playlistId)
    {
        return $this->delete($youtubeAccount, $playlistId);
    }

    private function delete(Tag $youtubeAccount, string $playlistId)
    {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->playlists->delete($playlistId);
    }
}
