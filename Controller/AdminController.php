<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Form\Type\AccountType;
use Pumukit\YoutubeBundle\Form\Type\YoutubePlaylistType;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route ("/admin/youtube")
 */
class AdminController extends AbstractController
{
    private $documentManager;
    private $translator;
    private $tagService;

    public function __construct(
        DocumentManager $documentManager,
        TranslatorInterface $translator,
        TagService $tagService
    ) {
        $this->documentManager = $documentManager;
        $this->translator = $translator;
        $this->tagService = $tagService;
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     *
     * @Route("/", name="pumukit_youtube_admin_index")
     */
    public function indexAction(): Response
    {
        return $this->render('@PumukitYoutube/Admin/index.html.twig', []);
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     *
     * @Route("/list", name="pumukit_youtube_admin_list")
     */
    public function listAction(): Response
    {
        $youtubeAccounts = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);
        if (!$youtubeAccounts) {
            throw new NotFoundHttpException($this->translator->trans('Youtube tag not defined'));
        }

        return $this->render('@PumukitYoutube/Admin/list.html.twig', ['youtubeAccounts' => $youtubeAccounts->getChildren()]);
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     *
     * @Route ("/create", name="pumukit_youtube_create_account")
     */
    public function createAction(Request $request)
    {
        $locale = $request->getLocale();
        $form = $this->createForm(AccountType::class, null, ['translator' => $this->translator, 'locale' => $locale]);
        $form->handleRequest($request);
        if ('POST' === $request->getMethod() && $form->isValid()) {
            try {
                $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                    'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
                ]);
                $data = $form->getData();
                $tag = new Tag();
                $tag->setMetatag(false);
                $tag->setProperty('login', $data['login']);
                $tag->setDisplay(false);
                $tag->seti18nTitle($data['i18n_title']);
                $tag->setParent($youtubeTag);
                $this->documentManager->persist($tag);
                $tag->setCod($tag->getId());
                $this->documentManager->flush();

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

        return $this->render('@PumukitYoutube/Admin/create.html.twig', ['form' => $form->createView()]);
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     *
     * @Route ("/edit/{id}", name="pumukit_youtube_edit_account")
     */
    public function editAction(Request $request, string $id)
    {
        $youtubeAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($id)]);
        if (!$youtubeAccount) {
            throw new \Exception('Youtube account not found');
        }
        $locale = $request->getLocale();
        $form = $this->createForm(AccountType::class, null, ['translator' => $this->translator, 'locale' => $locale]);
        $form->get('i18n_title')->setData($youtubeAccount->getI18nTitle());
        $form->get('login')->setData($youtubeAccount->getProperty('login'));
        $form->handleRequest($request);
        if ('POST' === $request->getMethod() && $form->isValid()) {
            try {
                $data = $form->getData();
                $youtubeAccount->setCod($youtubeAccount->getId());
                $youtubeAccount->setI18nTitle($data['i18n_title']);
                $youtubeAccount->setProperty('login', $data['login']);
                $this->documentManager->flush();

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

        return $this->render('@PumukitYoutube/Admin/edit.html.twig', [
            'form' => $form->createView(),
            'account' => $youtubeAccount,
        ]);
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     *
     * @Route ("/delete/{id}", name="pumukit_youtube_delete_tag")
     */
    public function deleteAction(string $id): ?JsonResponse
    {
        try {
            $youtubeAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(
                ['_id' => new ObjectId($id)]
            );
            $this->tagService->deleteTag($youtubeAccount);

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
     *
     * @route("/children/{id}", name="pumukit_youtube_children_tag")
     */
    public function childrenAction(Tag $tag): Response
    {
        return $this->render('@PumukitYoutube/Admin/children.html.twig', [
            'tag' => $tag,
            'youtubeAccounts' => $tag->getChildren(),
            'isPlaylist' => true,
        ]);
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     *
     * @Route ("/create/playlist/{id}", name="pumukit_youtube_create_playlist")
     */
    public function createPlaylistAction(Request $request, string $id)
    {
        $locale = $request->getLocale();
        $form = $this->createForm(YoutubePlaylistType::class, null, ['translator' => $this->translator, 'locale' => $locale]);
        $form->handleRequest($request);
        if ('POST' === $request->getMethod() && $form->isValid()) {
            try {
                $data = $form->getData();
                $playlist = new Tag();
                $playlist->setI18nTitle($data['i18n_title']);
                $playlist->setProperty('youtube_playlist', true);
                $this->documentManager->persist($playlist);
                $playlist->setCod($playlist->getId());
                $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($id)]);
                $playlist->setParent($account);
                $this->documentManager->flush();

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

        return $this->render('@PumukitYoutube/Admin/createPlaylist.html.twig', [
            'form' => $form->createView(),
            'account' => $id,
        ]);
    }

    /**
     * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
     *
     * @Route ("/edit/playlist/{id}", name="pumukit_youtube_edit_playlist")
     */
    public function editPlaylistAction(Request $request, string $id)
    {
        $locale = $request->getLocale();
        $playlist = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($id)]);
        $form = $this->createForm(YoutubePlaylistType::class, $playlist, ['translator' => $this->translator, 'locale' => $locale]);
        $form->handleRequest($request);
        if ('POST' === $request->getMethod() && $form->isValid()) {
            try {
                $data = $form->getData();
                $playlist->setI18nTitle($data->getI18nTitle());
                $this->documentManager->flush();

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

        return $this->render('@PumukitYoutube/Admin/editPlaylist.html.twig', [
            'form' => $form->createView(),
            'playlist' => $playlist,
        ]);
    }

    /**
     * @Route ("/update/config/{id}", name="pumukityoutube_advance_configuration_index")
     *
     * @ParamConverter("multimediaObject", options={"id" = "id"})
     */
    public function updateYTAction(MultimediaObject $multimediaObject): Response
    {
        $youtubeAccounts = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);
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

        return $this->render('@PumukitYoutube/Admin/updateYT.html.twig', [
            'youtubeAccounts' => $youtubeAccounts->getChildren(),
            'multimediaObject' => $multimediaObject,
            'accountId' => $accountSelectedTag,
            'playlistId' => $playlistSelectedTag,
        ]);
    }

    /**
     * @Route ("/playlist/list/{id}", name="pumukityoutube_playlist_select")
     */
    public function playlistAccountAction(string $id): JsonResponse
    {
        if (!$id) {
            return new JsonResponse([]);
        }
        $youtubeAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => $id]);
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
            'text' => $this->translator->trans('Without playlist'),
        ];
        array_unshift($children, $defaultOption);

        return new JsonResponse($children);
    }
}
