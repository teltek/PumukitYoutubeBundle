<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessage;
use Pumukit\YoutubeBundle\Domain\Service\AccountResolver;
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
    
    private $accountResolver;

    public function __construct(
        DocumentManager $documentManager,
        PlaylistItemInsertService $playlistItemInsertService,
        VideoDeleteService $videoDeleteService,
        MessageBusInterface $messageBus,
        LoggerInterface $logger,
        AccountResolver $accountResolver
    ) {
        $this->documentManager = $documentManager;
        $this->playlistItemInsertService = $playlistItemInsertService;
        $this->videoDeleteService = $videoDeleteService;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
        $this->accountResolver = $accountResolver;
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
            // This will be processed asynchronously by the UploadVideoMessageHandler
            $accountId = $this->accountResolver->resolveAccount($multimediaObject);
            $playlists = $this->accountResolver->resolvePlaylists($multimediaObject);
            
            $message = new UploadVideoMessage(
                multimediaObjectId: $multimediaObject->getId(),
                accountId: $accountId,
                playlists: $playlists
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
