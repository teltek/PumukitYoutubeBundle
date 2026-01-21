<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update\UpdatePlaylistRequest;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update\UpdatePlaylistService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/playlists-hexagonal")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
class UpdatePlaylistController extends AbstractController
{
    public function __construct(
        private UpdatePlaylistService $updatePlaylistService
    ) {}

    /**
     * @Route("/{id}/update", name="pumukit_youtube_playlists_hexagonal_update", methods={"PUT", "PATCH"})
     */
    public function __invoke(string $id, Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);

            $updateRequest = new UpdatePlaylistRequest(
                playlistId: $id,
                title: $data['title'] ?? null,
                description: $data['description'] ?? null,
                privacy: $data['privacy'] ?? null
            );

            $response = $this->updatePlaylistService->__invoke($updateRequest);

            return $this->json([
                'success' => true,
                'message' => 'Playlist updated successfully',
                'playlist' => [
                    'id' => $response->playlist->getId(),
                    'youtubeId' => $response->playlist->getYoutubeId(),
                    'title' => $response->playlist->getTitle(),
                    'description' => $response->playlist->getDescription(),
                    'privacy' => $response->playlist->getPrivacy(),
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to update playlist: '.$e->getMessage(),
            ], 500);
        }
    }
}
