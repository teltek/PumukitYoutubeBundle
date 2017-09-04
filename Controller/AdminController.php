<?php

namespace Pumukit\YoutubeBundle\Controller;

use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Form\Type\AccountType;
use Pumukit\YoutubeBundle\Form\Type\YoutubePlaylistType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;

class AdminController extends Controller
{
    private $youtubeTag = 'YOUTUBE';

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

        $youtubeAccounts = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => $this->youtubeTag));

        return array('youtubeAccounts' => $youtubeAccounts->getChildren());
    }

    /**
     * @param Request $request
     *
     * @return array|JsonResponse
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
                $youtubeTag = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
                    array('cod' => $this->youtubeTag)
                );

                $data = $form->getData();

                $tag = new Tag();
                $tag->setMetatag(false);
                $tag->setProperty('login', $data['login']);
                $tag->setDisplay(true);
                $tag->seti18nTitle($data['i18n_title']);
                $tag->setParent($youtubeTag);

                $dm->persist($tag);
                $tag->setCod($tag->getId());

                $dm->flush();

                return new JsonResponse(array('success'));
            } catch (\Exception $exception) {
                return new JsonResponse(
                    array(
                        'error',
                        'message' => $exception->getMessage(),
                    )
                );
            }
        }

        return array('form' => $form->createView());
    }

    /**
     * @param Request $request
     * @param         $id
     *
     * @return array|JsonResponse
     * @Route ("/edit/{id}", name="pumukit_youtube_edit_account")
     * @Template()
     */
    public function editAction(Request $request, $id)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();

        $youtubeAccount = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('_id' => new \MongoId($id)));

        $translator = $this->get('translator');
        $locale = $request->getLocale();

        $form = $this->createForm(new AccountType($translator, $locale));
        $form->get('i18n_title')->setData($youtubeAccount->getI18nTitle());
        $form->get('login')->setData($youtubeAccount->getProperty('login'));

        $form->handleRequest($request);
        if ($request->getMethod() === 'POST' && $form->isValid()) {
            try {
                $data = $form->getData();

                $youtubeAccount->setCod($youtubeAccount->getId());
                $youtubeAccount->setI18nTitle($data['i18n_title']);
                $youtubeAccount->setProperty('login', $data['login']);
                $dm->flush();

                return new JsonResponse(array('success'));
            } catch (\Exception $exception) {
                return new JsonResponse(
                    array(
                        'error',
                        'message' => $exception->getMessage(),
                    )
                );
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
     * @return JsonResponse
     * @Route ("/delete/{id}", name="pumukit_youtube_delete_tag")
     */
    public function deleteAction($id)
    {
        $tagService = $this->container->get('pumukitschema.tag');
        try {
            $dm = $this->get('doctrine_mongodb')->getManager();
            $youtubeAccount = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
                array('_id' => new \MongoId($id))
            );
            $tagService->deleteTag($youtubeAccount);

            return new JsonResponse(array('success'));
        } catch (\Exception $exception) {
            return new JsonResponse(
                array(
                    'error',
                    'message' => $exception->getMessage(),
                )
            );
        }
    }

    /**
     * @param Tag $tag
     * @route("/children/{id}", name="pumukit_youtube_children_tag")
     * @ParamConverter("tag", class="PumukitSchemaBundle:Tag")
     * @Template()
     *
     * @return array
     */
    public function childrenAction(Tag $tag)
    {
        return array(
            'tag' => $tag,
            'youtubeAccounts' => $tag->getChildren(),
            'isPlaylist' => true,
        );
    }

    /**
     * @param         $id
     * @param Request $request
     *
     * @return array|JsonResponse
     * @Route ("/create/playlist/{id}", name="pumukit_youtube_create_playlist")
     * @Template()
     */
    public function createPlaylistAction(Request $request, $id)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $translator = $this->get('translator');
        $locale = $request->getLocale();

        $form = $this->createForm(new YoutubePlaylistType($translator, $locale));

        $form->handleRequest($request);
        if ($request->getMethod() === 'POST' && $form->isValid()) {
            try {
                $data = $form->getData();

                $playlist = new Tag();
                $playlist->setI18nTitle($data['i18n_title']);
                $playlist->setProperty('youtube_playlist', true);
                $dm->persist($playlist);

                $playlist->setCod($playlist->getId());

                $account = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('_id' => new \MongoId($id)));
                $playlist->setParent($account);

                $dm->flush();

                return new JsonResponse(array('success'));
            } catch (\Exception $exception) {
                return new JsonResponse(
                    array(
                        'error',
                        'message' => $exception->getMessage(),
                    )
                );
            }
        }

        return array(
            'form' => $form->createView(),
            'account' => $id,
        );
    }

    /**
     * @param         $id
     * @param Request $request
     *
     * @return array|JsonResponse
     * @Route ("/edit/playlist/{id}", name="pumukit_youtube_edit_playlist")
     * @Template()
     */
    public function editPlaylistAction(Request $request, $id)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();
        $translator = $this->get('translator');
        $locale = $request->getLocale();

        $playlist = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('_id' => new \MongoId($id)));

        $form = $this->createForm(new YoutubePlaylistType($translator, $locale), $playlist);

        $form->handleRequest($request);
        if ($request->getMethod() === 'POST' && $form->isValid()) {
            try {
                $data = $form->getData();
                $playlist->setI18nTitle($data->getI18nTitle());
                $dm->flush();

                return new JsonResponse(array('success'));
            } catch (\Exception $exception) {
                return new JsonResponse(
                    array(
                        'error',
                        'message' => $exception->getMessage(),
                    )
                );
            }
        }

        return array(
            'form' => $form->createView(),
            'playlist' => $playlist,
        );
    }

    /**
     * @param MultimediaObject $multimediaObject
     *
     * @return array
     * @Route ("/update/config/{id}", name="pumukityoutube_advance_configuration_index")
     * @ParamConverter("multimediaObject", class="PumukitSchemaBundle:MultimediaObject", options={"id" = "id"})
     * @Template()
     */
    public function updateYTAction(MultimediaObject $multimediaObject)
    {
        $dm = $this->get('doctrine_mongodb')->getManager();

        $youtubeAccounts = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('cod' => $this->youtubeTag));

        return array(
            'youtubeAccounts' => $youtubeAccounts->getChildren(),
            'multimediaObject' => $multimediaObject,
        );
    }

    /**
     * @param $id
     *
     * @return JsonResponse
     * @Route ("/playlist/list/{id}", name="pumukityoutube_playlist_select")
     */
    public function playlistAccountAction($id = null)
    {
        if (isset($id)) {
            $dm = $this->get('doctrine_mongodb')->getManager();
            $youtubeAccount = $dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(array('_id' => $id));

            $children = array();
            foreach ($youtubeAccount->getChildren() as $child) {
                $children[] = array(
                    'id' => $child->getId(),
                    'text' => $child->getTitle(),
                );
            }

            $children = json_encode($children);

            return new JsonResponse($children);
        } else {
            return new JsonResponse(array());
        }
    }
}
