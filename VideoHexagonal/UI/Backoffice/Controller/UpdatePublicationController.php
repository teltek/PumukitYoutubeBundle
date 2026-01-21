<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\VideoHexagonal\Application\UpdatePublication\UpdatePublicationMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class UpdatePublicationController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/videos-hexagonal/{id}/publication", name="pumukit_youtube_video_hexagonal_publication", methods={"POST"})
     */
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['privacy'])) {
            return new JsonResponse(['error' => 'Missing privacy field'], 400);
        }

        $message = new UpdatePublicationMessage($id, $data['privacy']);

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Publication update enqueued successfully',
            'multimediaObjectId' => $id,
            'privacy' => $data['privacy'],
        ], 202);
    }
}
