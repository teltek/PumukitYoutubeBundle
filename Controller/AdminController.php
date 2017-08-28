<?php

namespace Pumukit\YoutubeBundle\Controller;

use Pumukit\YoutubeBundle\Document\YoutubeAccount;
use Pumukit\YoutubeBundle\Form\Type\AccountType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

class AdminController extends Controller
{
    /**
     * @Route ("/", name="pumukit_youtube_admin_index")
     * @Template()
     */
    public function indexAction()
    {
        return array();
    }

    /**
     * @Route ("/list", name="pumukit_youtube_admin_list")
     * @Template()
     */
    public function listAction()
    {
        $dm = $this->get('doctrine_mongodb')->getManager();

        $youtubeAccounts = $dm->getRepository('PumukitYoutubeBundle:YoutubeAccount')->findAll();

        return array('youtubeAccounts' => $youtubeAccounts);
    }

    /**
     * @param Request $request
     *
     * @return array|JsonResponse
     *
     * @Route ("/create", name="pumukit_youtube_create_account")
     * @Template()
     */
    public function createAction(Request $request)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $translator = $this->get('translator');
        $locale = $request->getLocale();

        $form = $this->createForm(new AccountType($translator, $locale));

        $form->handleRequest($request);
        if ($request->getMethod() === 'POST' && $form->isValid()) {
            try {
                $data = $form->getData();

                $youtubeAccount = new YoutubeAccount();
                $youtubeAccount->setName($data['name']);
                $youtubeAccount->setLogin($data['login']);
                $dm->persist($youtubeAccount);
                $dm->flush();

                return new JsonResponse(array('success'));
            } catch (\Exception $exception) {
                return new JsonResponse(array(
                    'error',
                    'message' => $exception->getMessage(),
                ));
            }
        }

        return array('form' => $form->createView());
    }

    /**
     * @param Request $request
     * @param $id
     *
     * @return array|JsonResponse
     *
     * @Route ("/edit/{id}", name="pumukit_youtube_edit_account")
     * @Template()
     */
    public function editAction(Request $request, $id)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();

        $youtubeAccount = $dm->getRepository('PumukitYoutubeBundle:YoutubeAccount')->findOneBy(array('_id' => new \MongoId($id)));

        $translator = $this->get('translator');
        $locale = $request->getLocale();

        $form = $this->createForm(new AccountType($translator, $locale), $youtubeAccount);

        $form->handleRequest($request);
        if ($request->getMethod() === 'POST' && $form->isValid()) {
            try {
                $data = $form->getData();

                $youtubeAccount->setName($data->getName());
                $youtubeAccount->setLogin($data->getLogin());
                $dm->flush();

                return new JsonResponse(array('success'));
            } catch (\Exception $exception) {
                return new JsonResponse(array(
                    'error',
                    'message' => $exception->getMessage(),
                ));
            }
        }

        return array(
            'form' => $form->createView(),
            'account' => $youtubeAccount,
        );
    }

    /**
     * @param $id
     *
     * @throws \Exception
     *
     * @return JsonResponse
     *
     * @Route ("/delete/{id}", name="pumukit_youtube_delete_account")
     */
    public function deleteAction($id)
    {
        try {
            $dm = $this->get('doctrine_mongodb')->getManager();
            $youtubeAccount = $dm->getRepository('PumukitYoutubeBundle:YoutubeAccount')->findOneBy(array('_id' => new \MongoId($id)));
            $dm->remove($youtubeAccount);
            $dm->flush();

            return new JsonResponse(array('success'));
        } catch (\Exception $exception) {
            return new JsonResponse(array(
                'error',
                'message' => $exception->getMessage(),
            ));
        }
    }

    /**
     * @param MultimediaObject $multimediaObject
     * @return array
     *
     * @Route ("/update/config/{id}", name="pumukityoutube_advance_configuration_index")
     * @ParamConverter("multimediaObject", class="PumukitSchemaBundle:MultimediaObject", options={"id" = "id"})
     * @Template()
     */
    public function updateYTAction(MultimediaObject $multimediaObject)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();

        $youtubeAccounts = $dm->getRepository('PumukitYoutubeBundle:YoutubeAccount')->findAll();

        return array('youtubeAccounts' => $youtubeAccounts, 'multimediaObject' => $multimediaObject);
    }

    /**
     * @param $id
     * @return JsonResponse
     *
     * @Route ("/playlist/list/{id}", name="pumukityoutube_playlist_select")
     */
    public function playlistAccountAction($id = null)
    {
        if($id) {
            $dm = $this->get('doctrine_mongodb')->getManager();
            $youtubeAccount = $dm->getRepository('PumukitYoutubeBundle:YoutubeAccount')->findOneBy(array('_id' => $id));

            return new JsonResponse(array('playlists' => $youtubeAccount->getPlaylist()));
        } else {
            return new JsonResponse(array());
        }
    }

}
