<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Application\Message\Playlist\UpdatePlaylistItemsMessage;
use Pumukit\YoutubeBundle\Application\Message\Video\UploadYoutubeVideoMessage;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\PlaylistItemInsertService;
use Pumukit\YoutubeBundle\Services\VideoDeleteService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route ("/admin/youtube")
 */
class ModalController extends AbstractController
{
    private $documentManager;

    private $playlistItemInsertService;

    private $videoDeleteService;
    
    private $messageBus;
    
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        PlaylistItemInsertService $playlistItemInsertService,
        VideoDeleteService $videoDeleteService,
        MessageBusInterface $messageBus,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->playlistItemInsertService = $playlistItemInsertService;
        $this->videoDeleteService = $videoDeleteService;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
    }

    /**
     * @Route ("/modal/mm/{id}", name="pumukityoutube_modal_index")
     */
    public function indexAction(MultimediaObject $mm): Response
    {
        $youtube = $this->documentManager->getRepository(Youtube::class)->find($mm->getProperty('youtube'));
        if (!isset($youtube)) {
            return $this->render('@PumukitYoutube/Modal/404notfound.html.twig', ['mm' => $mm]);
        }

        return $this->render('@PumukitYoutube/Modal/index.html.twig', [
            'mm' => $mm,
            'youtube' => $youtube,
            'youtube_status' => $youtube->getStatusText(),
        ]);
    }

    /**
     * @Route ("/updateplaylist/mm/{id}", name="pumukityoutube_updateplaylist")
     */
    public function updatePlaylistAction(MultimediaObject $multimediaObject): JsonResponse
    {
        // Despachar mensaje para procesamiento asíncrono
        $message = new UpdatePlaylistItemsMessage(
            multimediaObjectId: $multimediaObject->getId()
        );

        $this->messageBus->dispatch($message);

        $this->logger->info('[YouTube Modal] Playlist update message dispatched', [
            'multimediaObjectId' => $multimediaObject->getId(),
            'title' => $multimediaObject->getTitle(),
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Playlist update queued successfully. The playlists will be updated in the background.',
        ]);
    }

    /**
     * @Route ("/forceuploads/mm/{id}", name="pumukityoutube_force_upload")
     */
    public function forceUploadAction(MultimediaObject $multimediaObject): JsonResponse
    {
        try {
            // Delete video from YouTube first (if exists)
            $response = $this->videoDeleteService->deleteVideoFromYouTubeByMultimediaObject($multimediaObject);
            if (!$response) {
                return new JsonResponse(['error' => 'Cannot remove multimedia object from Youtube.']);
            }

            // Dispatch upload message using Symfony Messenger
            // This will be processed asynchronously by the UploadYoutubeVideoMessageHandler
            $message = new UploadYoutubeVideoMessage(
                multimediaObjectId: $multimediaObject->getId(),
                accountName: null, // Will use default account from configuration
                forceReupload: true
            );

            $this->messageBus->dispatch($message);

            $this->logger->info('[YouTube Modal] Force upload message dispatched', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'title' => $multimediaObject->getTitle(),
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'YouTube upload queued successfully. The video will be uploaded in the background.',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[YouTube Modal] Error dispatching force upload message', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'Error queueing YouTube upload: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
