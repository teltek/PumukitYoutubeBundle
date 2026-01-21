<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\VideoHexagonal\Application\Delete\DeleteVideoMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class DeleteVideoController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/videos-hexagonal/{id}/delete", name="pumukit_youtube_video_hexagonal_delete", methods={"DELETE"})
     */
    public function __invoke(string $id): JsonResponse
    {
        $message = new DeleteVideoMessage($id);

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Video deletion enqueued successfully',
            'multimediaObjectId' => $id,
        ], 202);
    }
}
