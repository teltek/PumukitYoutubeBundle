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
     *
     * @param MultimediaObject $mm
     *
     * @return array|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction(MultimediaObject $mm)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $youtubeRepo = $dm->getRepository('PumukitYoutubeBundle:Youtube');
        $youtube = $youtubeRepo->find($mm->getProperty('youtube'));

        if (!isset($youtube)) {
            return $this->render('PumukitYoutubeBundle:Modal:404notfound.html.twig', ['mm' => $mm]);
        }

        $youtubeStatus = 'none';
        switch ($youtube->getStatus()) {
            case Youtube::STATUS_DEFAULT:
            case Youtube::STATUS_UPLOADING:
            case Youtube::STATUS_PROCESSING:
                $youtubeStatus = 'proccessing';

                break;
            case Youtube::STATUS_PUBLISHED:
                $youtubeStatus = 'published';

                break;
            case Youtube::STATUS_ERROR:
                $youtubeStatus = 'error';

                break;
            case Youtube::STATUS_DUPLICATED:
                $youtubeStatus = 'duplicated';

                break;
            case Youtube::STATUS_REMOVED:
                $youtubeStatus = 'removed';

                break;
            case Youtube::STATUS_TO_DELETE:
                $youtubeStatus = 'to delete';

                break;
        }

        return ['mm' => $mm, 'youtube' => $youtube, 'youtube_status' => $youtubeStatus];
    }

    /**
     * @Route ("/updateplaylist/mm/{id}", name="pumukityoutube_updateplaylist")
     *
     * @param MultimediaObject $multimediaObject
     *
     * @throws \Exception
     *
     * @return JsonResponse
     */
    public function updateplaylistAction(MultimediaObject $multimediaObject)
    {
        $youtubePlaylistService = $this->get('pumukityoutube.youtube_playlist');
        $out = $youtubePlaylistService->updatePlaylists($multimediaObject);

        return new JsonResponse($out);
    }

    /**
     * @Route ("/forceuploads/mm/{id}", name="pumukityoutube_force_upload")
     *
     * @param MultimediaObject $multimediaObject
     *
     * @throws \Exception
     *
     * @return JsonResponse
     */
    public function forceUploadAction(MultimediaObject $multimediaObject)
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
