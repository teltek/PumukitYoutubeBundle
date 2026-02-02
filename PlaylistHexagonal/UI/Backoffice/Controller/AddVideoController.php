<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\AddVideo\AddVideoMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class AddVideoController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/playlists/{id}/add-video", name="pumukit_youtube_playlist_add_video", methods={"POST"})
     */
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['multimediaObjectId'])) {
            return new JsonResponse(['error' => 'Missing multimediaObjectId'], 400);
        }

        $message = new AddVideoMessage($id, $data['multimediaObjectId']);

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Video addition enqueued successfully',
            'playlistId' => $id,
            'multimediaObjectId' => $data['multimediaObjectId'],
        ], 202);
    }
}
