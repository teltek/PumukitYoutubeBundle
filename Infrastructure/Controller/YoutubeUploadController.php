<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Infrastructure\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessage;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Security("is_granted('ROLE_ACCESS_MULTIMEDIA_SERIES')")
 */
class YoutubeUploadController extends AbstractController
{
    private MessageBusInterface $messageBus;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        MessageBusInterface $messageBus,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->messageBus = $messageBus;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    /**
     * @Route("/youtube/upload/{id}", name="pumukit_youtube_upload", methods={"POST"})
     */
    public function uploadAction(string $id): Response
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find(new ObjectId($id));

            if (!$multimediaObject) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'MultimediaObject not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Check if video is already uploaded to YouTube
            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            if ($youtubeVideoId) {
                $this->logger->warning('[YouTube Backoffice] Upload attempted for already uploaded video', [
                    'multimediaObjectId' => $multimediaObject->getId(),
                    'youtubeVideoId' => $youtubeVideoId,
                    'title' => $multimediaObject->getTitle(),
                    'user' => $this->getUser()?->getUsername(),
                ]);

                return new JsonResponse([
                    'status' => 'warning',
                    'message' => 'This video is already uploaded to YouTube (ID: ' . $youtubeVideoId . '). Use "Force Re-upload" if you want to upload it again.',
                    'youtubeVideoId' => $youtubeVideoId,
                ], Response::HTTP_OK);
            }

            // Dispatch upload message using VideoHexagonal (new async architecture)
            // This will be processed asynchronously by the UploadVideoMessageHandler
            $message = new UploadVideoMessage(
                $multimediaObject->getId(),
                'default' // TODO: Get actual account from user/configuration
            );

            $this->messageBus->dispatch($message);

            $this->logger->info('[YouTube Backoffice] Upload message dispatched (VideoHexagonal)', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'title' => $multimediaObject->getTitle(),
                'user' => $this->getUser()?->getUsername(),
            ]);

            return new JsonResponse([
                'status' => 'enqueued',
                'message' => 'YouTube upload queued successfully. The video will be uploaded in the background.',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[YouTube Backoffice] Error dispatching upload message', [
                'multimediaObjectId' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Error queueing YouTube upload: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
