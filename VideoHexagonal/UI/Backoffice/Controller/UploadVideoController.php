<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class UploadVideoController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/videos-hexagonal/upload", name="pumukit_youtube_video_hexagonal_upload", methods={"POST"})
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['multimediaObjectId']) || !isset($data['accountId'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $message = new UploadVideoMessage(
            $data['multimediaObjectId'],
            $data['accountId']
        );

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Video upload enqueued successfully',
            'multimediaObjectId' => $data['multimediaObjectId'],
        ], 202);
    }
}
