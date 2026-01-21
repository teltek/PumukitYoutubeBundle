<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Infrastructure\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller para la configuración de publicación en YouTube
 * Carga datos desde Tags (sistema actual) para compatibilidad
 * 
 * @Route("/admin/youtube/publication-config")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
class YoutubePublicationConfigController extends AbstractController
{
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(DocumentManager $documentManager, LoggerInterface $logger)
    {
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    /**
     * Renderiza el widget de configuración avanzada de YouTube
     * 
     * @Route("/{id}", name="pumukit_youtube_publication_config", methods={"GET"})
     */
    public function configWidget(string $id): Response
    {
        $multimediaObject = $this->documentManager
            ->getRepository(MultimediaObject::class)
            ->find($id);

        if (!$multimediaObject) {
            throw $this->createNotFoundException('MultimediaObject not found');
        }

        // Cargar cuentas de YouTube desde TAGS (sistema actual)
        $youtubeRootTag = $this->documentManager->getRepository(Tag::class)
            ->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);

        $this->logger->info('[YoutubePublicationConfig] Loading YouTube accounts', [
            'youtubeRootTag' => $youtubeRootTag ? $youtubeRootTag->getId() : null,
            'tagCode' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        $youtubeAccounts = [];
        if ($youtubeRootTag) {
            // Cargar cuentas manualmente ya que getChildren() puede no funcionar con lazy loading
            $accountTags = $this->documentManager->getRepository(Tag::class)
                ->findBy(['parent.$id' => new ObjectId($youtubeRootTag->getId())]);
            
            $this->logger->info('[YoutubePublicationConfig] Found account tags', [
                'count' => count($accountTags),
            ]);
            
            foreach ($accountTags as $accountTag) {
                $youtubeAccounts[] = [
                    'id' => $accountTag->getId(),
                    'accountName' => $accountTag->getTitle(),
                    'login' => $accountTag->getProperty('login'),
                ];
            }
        }
        
        $this->logger->info('[YoutubePublicationConfig] Accounts prepared', [
            'accountsCount' => count($youtubeAccounts),
            'accounts' => $youtubeAccounts,
        ]);

        // Obtener configuración actual del MultimediaObject desde TAGS
        $selectedAccountId = null;
        $selectedPlaylists = [];

        // Buscar tags de YouTube asignados al MM
        foreach ($multimediaObject->getTags() as $tag) {
            if ($youtubeRootTag && $tag->isDescendantOf($youtubeRootTag)) {
                // Nivel 3 = Cuenta
                if (3 === (int) $tag->getLevel()) {
                    $selectedAccountId = $tag->getId();
                } 
                // Nivel 4 = Playlist
                elseif (4 === (int) $tag->getLevel()) {
                    $selectedPlaylists[] = $tag->getId();
                }
            }
        }

        return $this->render('@PumukitYoutube/PublicationConfig/widget.html.twig', [
            'youtubeAccounts' => $youtubeAccounts,
            'multimediaObject' => $multimediaObject,
            'selectedAccountId' => $selectedAccountId,
            'selectedPlaylists' => $selectedPlaylists,
        ]);
    }

    /**
     * Lista las playlists de una cuenta desde TAGS
     * 
     * @Route("/playlists/{accountId}", name="pumukit_youtube_publication_playlists", methods={"GET"})
     */
    public function getPlaylists(string $accountId): JsonResponse
    {
        $this->logger->info('[YoutubePublicationConfig] Loading playlists for account', [
            'accountId' => $accountId,
        ]);

        $data = [];
        
        try {
            // Buscar el tag de la cuenta directamente
            $accountTag = $this->documentManager->getRepository(Tag::class)
                ->find($accountId);

            if (!$accountTag) {
                $this->logger->warning('[YoutubePublicationConfig] Account tag not found', [
                    'accountId' => $accountId,
                ]);
                return new JsonResponse($data);
            }

            $this->logger->info('[YoutubePublicationConfig] Found account tag', [
                'tagId' => $accountTag->getId(),
                'title' => $accountTag->getTitle(),
            ]);

            // Cargar playlists como hijos del tag de cuenta
            $playlistTags = $this->documentManager->getRepository(Tag::class)
                ->findBy(['parent.$id' => new ObjectId($accountTag->getId())]);
            
            $this->logger->info('[YoutubePublicationConfig] Found playlist tags', [
                'count' => count($playlistTags),
            ]);

            foreach ($playlistTags as $playlistTag) {
                $data[] = [
                    'id' => $playlistTag->getId(),
                    'text' => $playlistTag->getTitle(),
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('[YoutubePublicationConfig] Error loading playlists', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return new JsonResponse($data);
    }

    /**
     * Lista todas las cuentas desde TAGS
     * 
     * @Route("/accounts", name="pumukit_youtube_publication_accounts", methods={"GET"})
     */
    public function getAccounts(): JsonResponse
    {
        $youtubeRootTag = $this->documentManager->getRepository(Tag::class)
            ->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);

        $data = [];
        
        if ($youtubeRootTag) {
            // Cargar cuentas manualmente
            $accountTags = $this->documentManager->getRepository(Tag::class)
                ->findBy(['parent.$id' => new ObjectId($youtubeRootTag->getId())]);
            
            foreach ($accountTags as $accountTag) {
                $data[] = [
                    'id' => $accountTag->getId(),
                    'name' => $accountTag->getTitle(),
                    'login' => $accountTag->getProperty('login'),
                ];
            }
        }

        return new JsonResponse($data);
    }
}
