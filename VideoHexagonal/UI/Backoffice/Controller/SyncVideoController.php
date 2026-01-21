<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\VideoHexagonal\Application\Sync\SyncVideoMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class SyncVideoController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/videos-hexagonal/{id}/sync", name="pumukit_youtube_video_hexagonal_sync", methods={"POST"})
     */
    public function __invoke(string $id): JsonResponse
    {
        $message = new SyncVideoMessage($id);

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Video sync enqueued successfully',
            'multimediaObjectId' => $id,
        ], 202);
    }
}
