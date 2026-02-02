<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\CaptionHexagonal\Application\List\ListCaptionsRequest;
use Pumukit\YoutubeBundle\CaptionHexagonal\Application\List\ListCaptionsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class ListCaptionsController extends AbstractController
{
    private ListCaptionsService $listCaptionsService;

    public function __construct(ListCaptionsService $listCaptionsService)
    {
        $this->listCaptionsService = $listCaptionsService;
    }

    /**
     * @Route("/admin/youtube/captions/{youtubeId}", name="pumukit_youtube_caption_list", methods={"GET"})
     */
    public function __invoke(string $youtubeId): JsonResponse
    {
        try {
            $request = new ListCaptionsRequest($youtubeId);
            $response = $this->listCaptionsService->__invoke($request);

            return new JsonResponse($response->toArray());
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
