<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Create\CreatePlaylistMessage;
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
class CreatePlaylistController extends AbstractController
{
    public function __construct(
        private MessageBusInterface $messageBus
    ) {}

    /**
     * @Route("/create", name="pumukit_youtube_playlists_create", methods={"POST"})
     */
    public function __invoke(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Crear mensaje para la cola
            $message = new CreatePlaylistMessage(
                accountId: $data['accountId'] ?? '',
                title: $data['title'] ?? '',
                description: $data['description'] ?? '',
                privacy: $data['privacy'] ?? 'private'
            );

            // Despachar a RabbitMQ (asíncrono)
            $this->messageBus->dispatch($message);

            return $this->json([
                'success' => true,
                'message' => 'Playlist creation enqueued successfully (ASYNC)',
                'status' => 'queued',
                'info' => 'The playlist will be created asynchronously by a worker',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to enqueue playlist creation: '.$e->getMessage(),
            ], 500);
        }
    }
}
