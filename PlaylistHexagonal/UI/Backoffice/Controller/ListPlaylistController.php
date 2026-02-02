<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List\ListPlaylistRequest;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List\ListPlaylistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal controller for rendering playlist list table.
 * Called via render(controller(...)) from PlaylistsIndexController.
 * No route annotation - not directly accessible.
 */
class ListPlaylistController extends AbstractController
{
    public function __construct(
        private ListPlaylistService $listPlaylistService
    ) {}

    /**
     * Renders playlist list table only (for embedding in main page via render(controller()))
     */
    public function __invoke(Request $request): Response
    {
        try {
            $accountId = $request->query->get('account');

            $listRequest = new ListPlaylistRequest($accountId);
            $response = $this->listPlaylistService->__invoke($listRequest);

            // Check if JSON response is requested (via AJAX)
            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json([
                    'success' => true,
                    'playlists' => array_map(function ($playlist) {
                        return [
                            'id' => $playlist->getId(),
                            'youtubeId' => $playlist->getYoutubeId(),
                            'title' => $playlist->getTitle(),
                            'description' => $playlist->getDescription(),
                            'privacy' => $playlist->getPrivacy(),
                            'videoCount' => $playlist->getVideoCount(),
                            'createdAt' => $playlist->getCreatedAt()->format('Y-m-d H:i:s'),
                        ];
                    }, $response->playlists),
                    'total' => $response->total,
                ]);
            }

            // Return HTML template response
            return $this->render('@PumukitYoutube/PlaylistHexagonal/Backoffice/list.html.twig', [
                'playlists' => $response->playlists,
                'accountId' => $accountId,
            ]);
        } catch (\Exception $e) {
            return $this->render('@PumukitYoutube/error.html.twig', [
                'message' => 'Error loading playlists: ' . $e->getMessage(),
            ]);
        }
    }
}
