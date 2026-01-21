<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List\ListPlaylistRequest;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List\ListPlaylistService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/playlists-hexagonal")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
class ListPlaylistController extends AbstractController
{
    public function __construct(
        private ListPlaylistService $listPlaylistService
    ) {}

    /**
     * @Route("/", name="pumukit_youtube_playlists_hexagonal_list", methods={"GET"})
     */
    public function __invoke(Request $request): Response
    {
        $accountId = $request->query->get('account');

        $listRequest = new ListPlaylistRequest($accountId);
        $response = $this->listPlaylistService->__invoke($listRequest);

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
}
