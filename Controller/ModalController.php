<?php

namespace Pumukit\YoutubeBundle\Controller;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;

class ModalController extends Controller
{
    /**
     * @Route ("/modal/mm/{id}", name="pumukityoutube_modal_index")
     * @Template()
     */
    public function indexAction(MultimediaObject $mm)
    {
        $dm = $this->get('doctrine_mongodb.odm.document_manager');

        $youtube = $dm->getRepository(Youtube::class)->find($mm->getProperty('youtube'));
        if (!isset($youtube)) {
            return $this->render('PumukitYoutubeBundle:Modal:404notfound.html.twig', ['mm' => $mm]);
        }

        $youtubeStatus = $youtube->getStatusText();

        return [
            'mm' => $mm,
            'youtube' => $youtube,
            'youtube_status' => $youtubeStatus,
        ];
    }

    /**
     * @Route ("/updateplaylist/mm/{id}", name="pumukityoutube_updateplaylist")
     *
     * @throws \Exception
     */
    public function updateplaylistAction(MultimediaObject $multimediaObject): JsonResponse
    {
        $youtubePlaylistService = $this->get('pumukityoutube.youtube_playlist');
        $out = $youtubePlaylistService->updatePlaylists($multimediaObject);

        return new JsonResponse($out);
    }

    /**
     * @Route ("/forceuploads/mm/{id}", name="pumukityoutube_force_upload")
     *
     * @throws \Exception
     */
    public function forceUploadAction(MultimediaObject $multimediaObject): JsonResponse
    {
        $syncStatus = $this->container->getParameter('pumukit_youtube.sync_status');
        $youtubeService = $this->get('pumukityoutube.youtube');
        $tagService = $this->get('pumukitschema.tag');
        $saveYoutubeAccount = $youtubeService->getMultimediaObjectYoutubeAccount($multimediaObject);
        $saveYoutubePlaylists = $youtubeService->getMultimediaObjectYoutubePlaylists($multimediaObject, $saveYoutubeAccount);
        $out = $youtubeService->delete($multimediaObject);
        if (0 !== $out) {
            return new JsonResponse($out);
        }
        $tagService->addTagToMultimediaObject($multimediaObject, $saveYoutubeAccount->getId());
        foreach ($saveYoutubePlaylists as $playlist) {
            if ($playlist instanceof Tag) {
                $tagService->addTagToMultimediaObject($multimediaObject, $playlist->getId());
            }
        }
        $status = 'public';
        if ($syncStatus) {
            $status = YoutubeService::$status[$multimediaObject->getStatus()];
        }
        $out = $youtubeService->upload($multimediaObject, 27, $status, true);

        return new JsonResponse($out);
    }
}
