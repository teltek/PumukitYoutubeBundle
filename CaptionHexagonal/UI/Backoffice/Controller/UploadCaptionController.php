<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\UI\Backoffice\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Application\Message\Caption\UploadCaptionsMessage as LegacyUploadCaptionsMessage;
use Pumukit\YoutubeBundle\CaptionHexagonal\Application\Upload\UploadCaptionMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class UploadCaptionController extends AbstractController
{
    private MessageBusInterface $messageBus;
    private DocumentManager $documentManager;

    public function __construct(MessageBusInterface $messageBus, DocumentManager $documentManager)
    {
        $this->messageBus = $messageBus;
        $this->documentManager = $documentManager;
    }

    /**
     * Accepts multipart/form-data from the UI, saves the uploaded caption temporarily and enqueues
     * a legacy UploadCaptionsMessage so the existing handler can process the file.
     *
     * @Route("/admin/youtube/captions/upload/{id}", name="pumukit_youtube_caption_upload", methods={"POST"})
     */
    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->find($id);

            if (!$multimediaObject) {
                return new JsonResponse(['success' => false, 'message' => 'MultimediaObject not found'], 404);
            }

            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            $accountId = $multimediaObject->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$accountId) {
                return new JsonResponse(['success' => false, 'message' => 'Video is not uploaded to YouTube'], 400);
            }

            $file = $request->files->get('caption_file');
            if (!$file) {
                return new JsonResponse(['success' => false, 'message' => 'No caption file provided'], 400);
            }

            $language = $request->request->get('language', 'en');
            $isDraft = $request->request->getBoolean('is_draft', false);

            // Save file temporarily
            $uploadDir = sys_get_temp_dir().'/pumukit_captions';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = uniqid('caption_').'.'.$file->getClientOriginalExtension();
            $filePath = $uploadDir.'/'.$fileName;
            $file->move($uploadDir, $fileName);

            // Dispatch legacy message that the existing handler expects
            $message = new LegacyUploadCaptionsMessage(
                (string) $multimediaObject->getId(),
                (string) $youtubeVideoId,
                (string) $accountId,
                (string) $language,
                $filePath
            );

            $this->messageBus->dispatch($message);

            return new JsonResponse([
                'success' => true,
                'message' => 'Caption upload queued successfully (HEXAGONAL ADAPTER)',
                'multimediaObjectId' => (string) $multimediaObject->getId(),
                'language' => $language,
            ], 202);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error queueing caption upload: '.$e->getMessage(),
            ], 500);
        }
    }
}
