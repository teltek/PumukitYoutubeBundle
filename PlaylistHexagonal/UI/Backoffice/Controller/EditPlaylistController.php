<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update\UpdatePlaylistRequest;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update\UpdatePlaylistService;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Infrastructure\Persistence\DoctrinePlaylistRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

/**
 * @Route("/admin/youtube/playlists-edit")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
final class EditPlaylistController extends AbstractController
{
    public function __construct(
        private DoctrinePlaylistRepository $playlistRepository,
        private UpdatePlaylistService $updatePlaylistService
    ) {}

    /**
     * @Route("/{id}", name="pumukit_youtube_playlists_edit_hexagonal", methods={"GET", "POST"})
     */
    public function __invoke(Request $request, string $id): Response
    {
        try {
            // Get the playlist
            $playlist = $this->playlistRepository->find($id);
            if (!$playlist) {
                return $this->json(['success' => false, 'message' => 'Playlist not found'], 404);
            }

            // If POST, update the playlist
            if ('POST' === $request->getMethod()) {
                $updateRequest = new UpdatePlaylistRequest(
                    $id,
                    $request->request->get('title', $playlist->getTitle()),
                    $request->request->get('description', $playlist->getDescription()),
                    $request->request->get('privacy', $playlist->getPrivacy())
                );
                $this->updatePlaylistService->__invoke($updateRequest);
                
                return $this->json([
                    'success' => true,
                    'message' => 'Playlist updated successfully',
                    'accountId' => $playlist->getAccountId(),
                ]);
            }

            // GET - Return form HTML for modal
            $formHtml = $this->renderView('@PumukitYoutube/PlaylistHexagonal/Backoffice/edit_modal_form.html.twig', [
                'playlist' => $playlist,
            ]);

            return $this->json([
                'success' => true,
                'formHtml' => $formHtml,
                'playlistId' => $playlist->getId(),
                'accountId' => $playlist->getAccountId(),
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
