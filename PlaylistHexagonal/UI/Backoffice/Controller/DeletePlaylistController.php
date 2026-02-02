<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete\DeletePlaylistMessage;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/playlists")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
class DeletePlaylistController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $messageBus
    ) {}

    /**
     * @Route("/{id}/delete", name="pumukit_youtube_playlists_delete", methods={"DELETE"})
     */
    public function __invoke(string $id): Response
    {
        try {
            // Enviar mensaje a la cola para procesamiento asincrónico
            $message = new DeletePlaylistMessage($id);
            $this->messageBus->dispatch($message);

            return $this->json([
                'success' => true,
                'message' => 'Playlist deletion queued for processing',
                'playlistId' => $id,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to queue playlist deletion: '.$e->getMessage(),
            ], 500);
        }
    }
}
