<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\VideoHexagonal\Application\Update\UpdateVideoMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class UpdateVideoController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/videos/{id}/update", name="pumukit_youtube_video_update", methods={"PUT"})
     */
    public function __invoke(string $id): JsonResponse
    {
        $message = new UpdateVideoMessage($id);

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Video update enqueued successfully',
            'multimediaObjectId' => $id,
        ], 202);
    }
}
