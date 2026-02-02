<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update\UpdatePlaylistMessage;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/playlists")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
class UpdatePlaylistController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $messageBus
    ) {}

    /**
     * @Route("/{id}/update", name="pumukit_youtube_playlists_update", methods={"PUT", "PATCH"})
     */
    public function __invoke(string $id, Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Crear mensaje para la cola
            $message = new UpdatePlaylistMessage(
                playlistId: $id,
                title: $data['title'] ?? null,
                description: $data['description'] ?? null,
                privacy: $data['privacy'] ?? null
            );

            // Despachar a RabbitMQ (asíncrono)
            $this->messageBus->dispatch($message);

            return $this->json([
                'success' => true,
                'message' => 'Playlist update queued for processing',
                'playlistId' => $id,
                'status' => 'queued',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to queue playlist update: '.$e->getMessage(),
            ], 500);
        }
    }
}
