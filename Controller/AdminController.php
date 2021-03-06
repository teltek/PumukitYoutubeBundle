<?php

namespace Pumukit\YoutubeBundle\Controller;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Form\Type\AccountType;
use Pumukit\YoutubeBundle\Form\Type\YoutubePlaylistType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class AdminController.
 */
class AdminController extends Controller
{
    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     * @Route ("/", name="pumukit_youtube_admin_index")
     * @Template()
     */
    public function indexAction(): array
    {
        return [];
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     * @Route ("/list", name="pumukit_youtube_admin_list")
     * @Template()
     */
    public function listAction(): array
    {
        $dm = $this->get('doctrine_mongodb.odm.document_manager');
        $translator = $this->get('translator');
        $youtubeAccounts = $dm->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        if (!$youtubeAccounts) {
            throw new NotFoundHttpException($translator->trans('Youtube tag not defined'));
        }

        return ['youtubeAccounts' => $youtubeAccounts->getChildren()];
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     * @Route ("/create", name="pumukit_youtube_create_account")
     * @Template()
     */
    public function createAction(Request $request)
    {
        $dm = $this->get('doctrine_mongodb.odm.document_manager');
        $translator = $this->get('translator');
        $locale = $request->getLocale();
        $form = $this->createForm(AccountType::class, null, ['translator' => $translator, 'locale' => $locale]);
        $form->handleRequest($request);
        if ('POST' === $request->getMethod() && $form->isValid()) {
            try {
                $youtubeTag = $dm->getRepository(Tag::class)->findOneBy(
                    ['cod' => Youtube::YOUTUBE_TAG_CODE]
                );
                $data = $form->getData();
                $tag = new Tag();
                $tag->setMetatag(false);
                $tag->setProperty('login', $data['login']);
                $tag->setDisplay(false);
                $tag->seti18nTitle($data['i18n_title']);
                $tag->setParent($youtubeTag);
                $dm->persist($tag);
                $tag->setCod($tag->getId());
                $dm->flush();

                return new JsonResponse(['success']);
            } catch (\Exception $exception) {
                return new JsonResponse(
                    [
                        'error',
                        'message' => $exception->getMessage(),
                    ]
                );
            }
        }

        return ['form' => $form->createView()];
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     * @Route ("/edit/{id}", name="pumukit_youtube_edit_account")
     * @Template()
     *
     * @throws \MongoException
     */
    public function editAction(Request $request, string $id)
    {
        $dm = $this->get('doctrine_mongodb.odm.document_manager');
        $youtubeAccount = $dm->getRepository(Tag::class)->findOneBy(['_id' => new \MongoId($id)]);
        if (!$youtubeAccount) {
            throw new \Exception('Youtube account not found');
        }
        $translator = $this->get('translator');
        $locale = $request->getLocale();
        $form = $this->createForm(AccountType::class, null, ['translator' => $translator, 'locale' => $locale]);
        $form->get('i18n_title')->setData($youtubeAccount->getI18nTitle());
        $form->get('login')->setData($youtubeAccount->getProperty('login'));
        $form->handleRequest($request);
        if ('POST' === $request->getMethod() && $form->isValid()) {
            try {
                $data = $form->getData();
                $youtubeAccount->setCod($youtubeAccount->getId());
                $youtubeAccount->setI18nTitle($data['i18n_title']);
                $youtubeAccount->setProperty('login', $data['login']);
                $dm->flush();

                return new JsonResponse(['success']);
            } catch (\Exception $exception) {
                return new JsonResponse(
                    [
                        'error',
                        'message' => $exception->getMessage(),
                    ]
                );
            }
        }

        return [
            'form' => $form->createView(),
            'account' => $youtubeAccount,
        ];
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     * @Route ("/delete/{id}", name="pumukit_youtube_delete_tag")
     */
    public function deleteAction(string $id): ?JsonResponse
    {
        $tagService = $this->container->get('pumukitschema.tag');

        try {
            $dm = $this->get('doctrine_mongodb.odm.document_manager');
            $youtubeAccount = $dm->getRepository(Tag::class)->findOneBy(
                ['_id' => new \MongoId($id)]
            );
            $tagService->deleteTag($youtubeAccount);

            return new JsonResponse(['success']);
        } catch (\Exception $exception) {
            return new JsonResponse(
                [
                    'error',
                    'message' => $exception->getMessage(),
                ]
            );
        }
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     * @route("/children/{id}", name="pumukit_youtube_children_tag")
     * @ParamConverter("tag", class="PumukitSchemaBundle:Tag")
     * @Template()
     */
    public function childrenAction(Tag $tag): array
    {
        return [
            'tag' => $tag,
            'youtubeAccounts' => $tag->getChildren(),
            'isPlaylist' => true,
        ];
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     * @Route ("/create/playlist/{id}", name="pumukit_youtube_create_playlist")
     * @Template()
     */
    public function createPlaylistAction(Request $request, string $id)
    {
        $dm = $this->get('doctrine_mongodb.odm.document_manager');
        $translator = $this->get('translator');
        $locale = $request->getLocale();
        $form = $this->createForm(YoutubePlaylistType::class, null, ['translator' => $translator, 'locale' => $locale]);
        $form->handleRequest($request);
        if ('POST' === $request->getMethod() && $form->isValid()) {
            try {
                $data = $form->getData();
                $playlist = new Tag();
                $playlist->setI18nTitle($data['i18n_title']);
                $playlist->setProperty('youtube_playlist', true);
                $dm->persist($playlist);
                $playlist->setCod($playlist->getId());
                $account = $dm->getRepository(Tag::class)->findOneBy(['_id' => new \MongoId($id)]);
                $playlist->setParent($account);
                $dm->flush();

                return new JsonResponse(['success']);
            } catch (\Exception $exception) {
                return new JsonResponse(
                    [
                        'error',
                        'message' => $exception->getMessage(),
                    ]
                );
            }
        }

        return [
            'form' => $form->createView(),
            'account' => $id,
        ];
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     * @Route ("/edit/playlist/{id}", name="pumukit_youtube_edit_playlist")
     * @Template()
     *
     * @throws \MongoException
     */
    public function editPlaylistAction(Request $request, string $id)
    {
        $dm = $this->get('doctrine_mongodb.odm.document_manager');
        $translator = $this->get('translator');
        $locale = $request->getLocale();
        $playlist = $dm->getRepository(Tag::class)->findOneBy(['_id' => new \MongoId($id)]);
        $form = $this->createForm(YoutubePlaylistType::class, $playlist, ['translator' => $translator, 'locale' => $locale]);
        $form->handleRequest($request);
        if ('POST' === $request->getMethod() && $form->isValid()) {
            try {
                $data = $form->getData();
                $playlist->setI18nTitle($data->getI18nTitle());
                $dm->flush();

                return new JsonResponse(['success']);
            } catch (\Exception $exception) {
                return new JsonResponse(
                    [
                        'error',
                        'message' => $exception->getMessage(),
                    ]
                );
            }
        }

        return [
            'form' => $form->createView(),
            'playlist' => $playlist,
        ];
    }

    /**
     * @Route ("/update/config/{id}", name="pumukityoutube_advance_configuration_index")
     * @ParamConverter("multimediaObject", class="PumukitSchemaBundle:MultimediaObject", options={"id" = "id"})
     * @Template()
     */
    public function updateYTAction(MultimediaObject $multimediaObject): array
    {
        $dm = $this->get('doctrine_mongodb.odm.document_manager');
        $youtubeAccounts = $dm->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_TAG_CODE]);
        $accountSelectedTag = '';
        $playlistSelectedTag = [];
        foreach ($multimediaObject->getTags() as $tag) {
            if ($tag->isDescendantOf($youtubeAccounts)) {
                if (3 === (int) $tag->getLevel()) {
                    $accountSelectedTag = $tag->getId();
                } elseif (4 === (int) $tag->getLevel()) {
                    $playlistSelectedTag[] = $tag->getId();
                }
            }
        }

        return [
            'youtubeAccounts' => $youtubeAccounts->getChildren(),
            'multimediaObject' => $multimediaObject,
            'accountId' => $accountSelectedTag,
            'playlistId' => $playlistSelectedTag,
        ];
    }

    /**
     * @Route ("/playlist/list/{id}", name="pumukityoutube_playlist_select")
     */
    public function playlistAccountAction(string $id): JsonResponse
    {
        if (!$id) {
            return new JsonResponse([]);
        }
        $dm = $this->get('doctrine_mongodb.odm.document_manager');
        $youtubeAccount = $dm->getRepository(Tag::class)->findOneBy(['_id' => $id]);
        if (!$youtubeAccount) {
            return new JsonResponse([]);
        }
        $children = [];
        foreach ($youtubeAccount->getChildren() as $child) {
            $children[] = [
                'id' => $child->getId(),
                'text' => $child->getTitle(),
            ];
        }
        $defaultOption = [
            'id' => 'any',
            'text' => $this->get('translator')->trans('Without playlist'),
        ];
        array_unshift($children, $defaultOption);

        return new JsonResponse($children);
    }
}
