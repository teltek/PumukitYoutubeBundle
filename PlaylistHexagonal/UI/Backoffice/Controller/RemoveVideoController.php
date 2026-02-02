<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\RemoveVideo\RemoveVideoMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class RemoveVideoController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/playlists/item/{playlistItemId}/remove", name="pumukit_youtube_playlist_remove_video", methods={"DELETE"})
     */
    public function __invoke(string $playlistItemId): JsonResponse
    {
        $message = new RemoveVideoMessage($playlistItemId);

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Video removal enqueued successfully',
            'playlistItemId' => $playlistItemId,
        ], 202);
    }
}
