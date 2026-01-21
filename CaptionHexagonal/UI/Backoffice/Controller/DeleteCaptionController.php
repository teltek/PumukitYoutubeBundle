<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\CaptionHexagonal\Application\Delete\DeleteCaptionMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class DeleteCaptionController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/admin/youtube/captions-hexagonal/{youtubeId}/{captionId}/delete", name="pumukit_youtube_caption_hexagonal_delete", methods={"DELETE"})
     */
    public function __invoke(string $youtubeId, string $captionId): JsonResponse
    {
        $message = new DeleteCaptionMessage($youtubeId, $captionId);

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Caption deletion enqueued successfully',
            'youtubeId' => $youtubeId,
            'captionId' => $captionId,
        ], 202);
    }
}
