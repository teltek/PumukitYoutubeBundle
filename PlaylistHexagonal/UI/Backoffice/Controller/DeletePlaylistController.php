<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete\DeletePlaylistRequest;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete\DeletePlaylistService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/playlists-hexagonal")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
class DeletePlaylistController extends AbstractController
{
    public function __construct(
        private DeletePlaylistService $deletePlaylistService
    ) {}

    /**
     * @Route("/{id}/delete", name="pumukit_youtube_playlists_hexagonal_delete", methods={"DELETE"})
     */
    public function __invoke(string $id): Response
    {
        try {
            $deleteRequest = new DeletePlaylistRequest($id);
            $response = $this->deletePlaylistService->__invoke($deleteRequest);

            return $this->json([
                'success' => true,
                'message' => 'Playlist deleted successfully',
                'deletedId' => $response->deletedPlaylistId,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to delete playlist: '.$e->getMessage(),
            ], 500);
        }
    }
}
