<?php

namespace Pumukit\YoutubeBundle\Controller;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class ModalController extends Controller
{
    /**
     * @Route ("/modal/mm/{id}", name="pumukityoutube_modal_index")
     * @Template()
     */
    public function indexAction(Request $request, MultimediaObject $mm)
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
            case Youtube::STATUS_HTTP_ERROR:
            case Youtube::STATUS_ERROR:
            case Youtube::STATUS_UPDATE_ERROR:
                $youtubeStatus = 'error';

                break;
            case Youtube::STATUS_DUPLICATED:
                $youtubeStatus = 'duplicated';

                break;
            case Youtube::STATUS_REMOVED:
                $youtubeStatus = 'removed';

                break;
            case Youtube::STATUS_NOTIFIED_ERROR:
                $youtubeStatus = 'notified error';

                break;
            case Youtube::STATUS_TO_DELETE:
                $youtubeStatus = 'to delete';

                break;
        }

        return ['mm' => $mm, 'youtube' => $youtube, 'youtube_status' => $youtubeStatus];
    }
}
