<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\CaptionHexagonal\Application\Upload\UploadCaptionMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class UploadCaptionController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/captions-hexagonal/upload", name="pumukit_youtube_caption_hexagonal_upload", methods={"POST"})
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['multimediaObjectId']) || !isset($data['language'])) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        $message = new UploadCaptionMessage(
            $data['multimediaObjectId'],
            $data['language']
        );

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Caption upload enqueued successfully',
            'multimediaObjectId' => $data['multimediaObjectId'],
            'language' => $data['language'],
        ], 202);
    }
}
