<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Pumukit\YoutubeBundle\Services\YoutubePlaylistService;
use Pumukit\YoutubeBundle\Services\YoutubeService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

class ModalController extends AbstractController
{
    private $documentManager;
    private $translator;
    private $youtubePlaylistService;
    private $youtubeService;
    private $tagService;
    private $configurationService;

    public function __construct(
        DocumentManager $documentManager,
        TranslatorInterface $translator,
        YoutubePlaylistService $youtubePlaylistService,
        YoutubeService $youtubeService,
        TagService $tagService,
        YoutubeConfigurationService $configurationService
    ) {
        $this->documentManager = $documentManager;
        $this->translator = $translator;
        $this->youtubePlaylistService = $youtubePlaylistService;
        $this->youtubeService = $youtubeService;
        $this->tagService = $tagService;
        $this->configurationService = $configurationService;
    }

    /**
     * @Route ("/modal/mm/{id}", name="pumukityoutube_modal_index")
     */
    public function indexAction(MultimediaObject $mm)
    {
        $youtube = $this->documentManager->getRepository(Youtube::class)->find($mm->getProperty('youtube'));
        if (!isset($youtube)) {
            return $this->render('@PumukitYoutube/Modal/404notfound.html.twig', ['mm' => $mm]);
        }

        $youtubeStatus = $youtube->getStatusText();

        return $this->render('@PumukitYoutube/Modal/index.html.twig', [
            'mm' => $mm,
            'youtube' => $youtube,
            'youtube_status' => $youtubeStatus,
        ]);
    }

    /**
     * @Route ("/updateplaylist/mm/{id}", name="pumukityoutube_updateplaylist")
     */
    public function updateplaylistAction(MultimediaObject $multimediaObject): JsonResponse
    {
        $out = $this->youtubePlaylistService->updatePlaylists($multimediaObject);

        return new JsonResponse($out);
    }

    /**
     * @Route ("/forceuploads/mm/{id}", name="pumukityoutube_force_upload")
     */
    public function forceUploadAction(MultimediaObject $multimediaObject): JsonResponse
    {
        $saveYoutubeAccount = $this->youtubeService->getMultimediaObjectYoutubeAccount($multimediaObject);
        $saveYoutubePlaylists = $this->youtubeService->getMultimediaObjectYoutubePlaylists($multimediaObject, $saveYoutubeAccount);
        $out = $this->youtubeService->delete($multimediaObject);
        if (0 !== $out) {
            return new JsonResponse($out);
        }
        $this->tagService->addTagToMultimediaObject($multimediaObject, $saveYoutubeAccount->getId());
        foreach ($saveYoutubePlaylists as $playlist) {
            if ($playlist instanceof Tag) {
                $this->tagService->addTagToMultimediaObject($multimediaObject, $playlist->getId());
            }
        }
        $status = 'public';
        if ($this->configurationService->getBundleConfiguration()['syncStatus']) {
            $status = YoutubeService::$status[$multimediaObject->getStatus()];
        }
        $out = $this->youtubeService->upload($multimediaObject, 27, $status, true);

        return new JsonResponse($out);
    }
}
