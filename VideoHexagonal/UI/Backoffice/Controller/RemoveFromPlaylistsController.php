<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\UI\Backoffice\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Playlist\RemoveFromPlaylistsMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class RemoveFromPlaylistsController extends AbstractController
{
    private MessageBusInterface $messageBus;
    private DocumentManager $documentManager;

    public function __construct(MessageBusInterface $messageBus, DocumentManager $documentManager)
    {
        $this->messageBus = $messageBus;
        $this->documentManager = $documentManager;
    }

    /**
     * @Route("/admin/youtube/video/{multimediaObjectId}/remove-from-playlists", name="pumukit_youtube_video_remove_from_playlists", methods={"POST"})
     */
    public function __invoke(string $multimediaObjectId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['playlistIds']) || !is_array($data['playlistIds']) || empty($data['playlistIds'])) {
            return new JsonResponse(['error' => 'Missing or invalid playlistIds'], 400);
        }

        if (!isset($data['accountId'])) {
            return new JsonResponse(['error' => 'Missing accountId'], 400);
        }

        // Get the YouTube document to verify video exists and get YouTube ID
        $youtubeDoc = $this->documentManager->getRepository(Youtube::class)
            ->findOneBy(['multimediaObjectId' => $multimediaObjectId]);

        if (!$youtubeDoc) {
            return new JsonResponse([
                'error' => 'Video not uploaded to YouTube yet',
                'multimediaObjectId' => $multimediaObjectId,
            ], 404);
        }

        $youtubeId = $youtubeDoc->getYoutubeId();

        if (!$youtubeId) {
            return new JsonResponse([
                'error' => 'YouTube ID not found for this video',
                'multimediaObjectId' => $multimediaObjectId,
            ], 400);
        }

        // Dispatch message to remove video from playlists
        $message = new RemoveFromPlaylistsMessage(
            $youtubeId,
            $data['accountId'],
            $data['playlistIds'],
            $multimediaObjectId
        );

        $this->messageBus->dispatch($message);

        return new JsonResponse([
            'status' => 'enqueued',
            'message' => 'Video will be removed from ' . count($data['playlistIds']) . ' playlist(s)',
            'multimediaObjectId' => $multimediaObjectId,
            'youtubeId' => $youtubeId,
            'playlistCount' => count($data['playlistIds']),
        ], 202);
    }
}
