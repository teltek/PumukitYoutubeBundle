<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Security\Permission;
use Pumukit\SchemaBundle\Services\MultimediaObjectVoter;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\PlaylistItemInsertService;
use Pumukit\YoutubeBundle\Services\VideoDeleteService;
use Pumukit\YoutubeBundle\Services\VideoInsertService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/youtube')]
class ModalController extends AbstractController
{
    public const CSRF_TOKEN = 'youtube_video';

    private $documentManager;

    private $playlistItemInsertService;

    private $videoDeleteService;
    private $videoInsertService;

    public function __construct(
        DocumentManager $documentManager,
        PlaylistItemInsertService $playlistItemInsertService,
        VideoDeleteService $videoDeleteService,
        VideoInsertService $videoInsertService
    ) {
        $this->documentManager = $documentManager;
        $this->playlistItemInsertService = $playlistItemInsertService;
        $this->videoDeleteService = $videoDeleteService;
        $this->videoInsertService = $videoInsertService;
    }

    #[Route('/modal/mm/{id}', name: 'pumukityoutube_modal_index')]
    #[IsGranted(Permission::ACCESS_MULTIMEDIA_SERIES)]
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

    #[Route('/updateplaylist/mm/{id}', name: 'pumukityoutube_updateplaylist', methods: ['POST'])]
    #[IsGranted('ROLE_ACCESS_YOUTUBE')]
    public function updatePlaylistAction(Request $request, MultimediaObject $multimediaObject): JsonResponse
    {
        $this->denyUnlessAllowed($request, $multimediaObject);

        $out = $this->playlistItemInsertService->updatePlaylist($multimediaObject);

        return new JsonResponse($out);
    }

    #[Route('/forceuploads/mm/{id}', name: 'pumukityoutube_force_upload', methods: ['POST'])]
    #[IsGranted('ROLE_ACCESS_YOUTUBE')]
    public function forceUploadAction(Request $request, MultimediaObject $multimediaObject): JsonResponse
    {
        $this->denyUnlessAllowed($request, $multimediaObject);

        // Validate the track that would be uploaded against the max upload size before
        // touching anything. If it does not fit, leave the Youtube document untouched
        // (the service already logged the attempt in youtube.log).
        if (!$this->videoInsertService->validateForceUploadSize($multimediaObject)) {
            return new JsonResponse(['error' => 'Cannot force upload: the file exceeds the maximum allowed upload size.']);
        }

        // Only delete from YouTube when the object is actually published there. A document
        // in TO_REVIEW (or any state without a youtubeId) has nothing to delete.
        $youtube = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);

        if ($youtube && $youtube->getYoutubeId()) {
            $response = $this->videoDeleteService->deleteVideoFromYouTubeByMultimediaObject($multimediaObject);
            if (!$response) {
                return new JsonResponse(['error' => 'Cannot remove multimedia object from Youtube.']);
            }
        }

        $out = $this->videoInsertService->uploadVideoToYoutube($multimediaObject);

        return new JsonResponse($out);
    }

    private function denyUnlessAllowed(Request $request, MultimediaObject $multimediaObject): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $this->denyAccessUnlessGranted(MultimediaObjectVoter::EDIT, $multimediaObject);
    }
}
