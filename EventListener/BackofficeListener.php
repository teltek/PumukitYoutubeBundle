<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\NewAdminBundle\Event\PublicationSubmitEvent;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessage;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\AddVideo\AddVideoMessage;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\RemoveVideo\RemoveVideoMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

class BackofficeListener
{
    private $documentManager;
    private $tagService;
    private $messageBus;
    private $logger;

    public function __construct(
        DocumentManager $documentManager, 
        TagService $tagService,
        MessageBusInterface $messageBus,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->tagService = $tagService;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
    }

    public function onPublicationSubmit(PublicationSubmitEvent $event): bool
    {
        error_log('[BackofficeListener] ===== LISTENER EJECUTÁNDOSE =====');
        
        $request = $event->getRequest();
        $multimediaObject = $event->getMultimediaObject();
        
        error_log('[BackofficeListener] MM ID: ' . $multimediaObject->getId());
        error_log('[BackofficeListener] Has youtube_label: ' . ($request->request->has('youtube_label') ? 'YES' : 'NO'));
        
        $this->logger->info('[BackofficeListener] Publication submit triggered', [
            'multimediaObjectId' => $multimediaObject->getId(),
            'hasYoutubeLabel' => $request->request->has('youtube_label'),
            'hasPlaylistLabel' => $request->request->has('youtube_playlist_label'),
            'youtubeLabel' => $request->request->get('youtube_label'),
            'playlistLabels' => $request->request->get('youtube_playlist_label'),
        ]);
        
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);
        if (!$youtubeTag) {
            $this->logger->warning('[BackofficeListener] YOUTUBE tag not found');
            return false;
        }

        foreach ($multimediaObject->getTags() as $embedTag) {
            if ($embedTag->isDescendantOf($youtubeTag)) {
                $this->tagService->removeTagFromMultimediaObject($multimediaObject, $embedTag->getId());
            }
        }

        if (!$request->request->has('pub_channels')) {
            return false;
        }

        $pubChannels = array_keys($request->request->get('pub_channels'));
        if (!in_array(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE, $pubChannels, true)) {
            return false;
        }

        if (!$request->request->has('youtube_label') && !$request->request->has('youtube_playlist_label')) {
            return false;
        }

        $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        $this->tagService->addTagToMultimediaObject($multimediaObject, new ObjectId($request->request->get('youtube_label')));
        
        $playlists = $request->request->get('youtube_playlist_label');
        if (is_array($playlists)) {
            $this->addPlaylistToMultimediaObject($multimediaObject, $playlists);
        }

        // Guardar accountId y playlists como properties del MultimediaObject
        $this->saveYoutubeConfigProperties($multimediaObject, $request);

        $youtubeDocument = $this->documentManager->getRepository(Youtube::class)->findOneBy(['multimediaObjectId' => $multimediaObject->getId()]);
        if ($youtubeDocument) {
            $accountLabel = $this->documentManager->getRepository(Tag::class)->findOneBy(['_id' => new ObjectId($request->request->get('youtube_label'))]);
            $differentAccount = $accountLabel && $youtubeDocument->getYoutubeAccount() !== $accountLabel->getProperty('login');
            if ($differentAccount) {
                $youtubeDocument->setStatus(Youtube::STATUS_TO_DELETE);
                $this->documentManager->flush();
            }
        }

        // Disparar mensaje hexagonal para subir/actualizar en YouTube
        $this->dispatchUploadMessage($multimediaObject, $request);

        return true;
    }

    /**
     * Dispara el mensaje hexagonal de upload a YouTube o actualiza playlists si ya está subido
     */
    private function dispatchUploadMessage(MultimediaObject $multimediaObject, Request $request): void
    {
        $this->logger->info('[BackofficeListener] Entering dispatchUploadMessage', [
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);
        
        // Obtener accountId del tag seleccionado
        $accountTagId = $request->request->get('youtube_label');
        
        if (!$accountTagId) {
            $this->logger->warning('[BackofficeListener] No youtube_label provided, skipping upload');
            return;
        }

        // Obtener el tag de la cuenta para sacar el login (accountId)
        $accountTag = $this->documentManager->getRepository(Tag::class)
            ->findOneBy(['_id' => new ObjectId($accountTagId)]);
        
        if (!$accountTag) {
            $this->logger->error('[BackofficeListener] Account tag not found', [
                'accountTagId' => $accountTagId,
            ]);
            return;
        }

        $accountId = $accountTag->getProperty('login');
        if (!$accountId) {
            $this->logger->error('[BackofficeListener] Account tag has no login property', [
                'accountTagId' => $accountTagId,
                'tagCod' => $accountTag->getCod(),
            ]);
            return;
        }
        
        $this->logger->info('[BackofficeListener] Account found', [
            'accountId' => $accountId,
            'accountTagCod' => $accountTag->getCod(),
        ]);

        // Obtener playlists seleccionadas
        $newPlaylists = $this->extractPlaylistsFromRequest($request);
        
        $this->logger->info('[BackofficeListener] Extracted playlists', [
            'newPlaylists' => $newPlaylists,
            'count' => count($newPlaylists),
        ]);

        // Verificar si el video ya existe en YouTube
        $youtubeDoc = $this->documentManager->getRepository(Youtube::class)
            ->findOneBy(['multimediaObjectId' => $multimediaObject->getId()]);
        
        if ($youtubeDoc && $youtubeDoc->getYoutubeId()) {
            // VIDEO YA EXISTE EN YOUTUBE - Optimizar con AddVideo/RemoveVideo
            $this->logger->info('[BackofficeListener] Video already exists in YouTube, dispatching playlist changes', [
                'youtubeId' => $youtubeDoc->getYoutubeId(),
                'status' => $youtubeDoc->getStatus(),
            ]);
            
            // Obtener playlists actuales del MultimediaObject
            $oldPlaylists = $multimediaObject->getProperty('youtube_playlists') ?? [];
            if (!is_array($oldPlaylists)) {
                $oldPlaylists = [];
            }
            
            $this->logger->info('[BackofficeListener] Comparing playlists', [
                'oldPlaylists' => $oldPlaylists,
                'newPlaylists' => $newPlaylists,
            ]);
            
            // Despachar cambios de playlist
            $this->dispatchPlaylistChanges($multimediaObject, $accountId, $oldPlaylists, $newPlaylists);
            
        } else {
            // VIDEO NUEVO - Upload completo con playlists
            $this->logger->info('[BackofficeListener] New video upload, dispatching UploadVideoMessage', [
                'playlists' => $newPlaylists,
            ]);
            
            $message = new UploadVideoMessage(
                $multimediaObject->getId(),
                $accountId,
                $newPlaylists
            );

            $this->messageBus->dispatch($message);

            $this->logger->info('[BackofficeListener] Upload message dispatched', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'accountId' => $accountId,
                'playlists' => $newPlaylists,
            ]);
        }
    }

    /**
     * Extrae los IDs de playlists de YouTube del request
     */
    private function extractPlaylistsFromRequest(Request $request): array
    {
        $playlistLabels = $request->request->get('youtube_playlist_label', []);
        $playlists = [];
        
        foreach ($playlistLabels as $playlistTagId) {
            if ($playlistTagId === 'any') {
                continue; // Skip "Without playlist" option
            }
            
            $playlistTag = $this->documentManager->getRepository(Tag::class)
                ->findOneBy(['_id' => new ObjectId($playlistTagId)]);
            
            if ($playlistTag) {
                $youtubePlaylistId = $playlistTag->getProperty('youtube_playlist_id');
                if ($youtubePlaylistId) {
                    $playlists[] = $youtubePlaylistId;
                    $this->logger->debug('[BackofficeListener] Added playlist', [
                        'tagId' => $playlistTagId,
                        'youtubePlaylistId' => $youtubePlaylistId,
                        'title' => $playlistTag->getTitle(),
                    ]);
                } else {
                    $this->logger->warning('[BackofficeListener] Playlist tag has no youtube_playlist_id', [
                        'tagId' => $playlistTagId,
                        'title' => $playlistTag->getTitle(),
                    ]);
                }
            }
        }
        
        return $playlists;
    }

    /**
     * Despacha mensajes de Add/Remove para cambios en playlists
     */
    private function dispatchPlaylistChanges(
        MultimediaObject $multimediaObject,
        string $accountId,
        array $oldPlaylists,
        array $newPlaylists
    ): void {
        // Calcular diferencias
        $toRemove = array_diff($oldPlaylists, $newPlaylists);
        $toAdd = array_diff($newPlaylists, $oldPlaylists);
        
        $this->logger->info('[BackofficeListener] Playlist changes calculated', [
            'toRemove' => array_values($toRemove),
            'toAdd' => array_values($toAdd),
        ]);
        
        // Despachar RemoveVideoMessage para cada playlist removida
        foreach ($toRemove as $playlistId) {
            $message = new RemoveVideoMessage(
                $multimediaObject->getId(),
                $playlistId,
                $accountId
            );
            
            $this->messageBus->dispatch($message);
            
            $this->logger->info('[BackofficeListener] RemoveVideoMessage dispatched', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'playlistId' => $playlistId,
                'accountId' => $accountId,
            ]);
        }
        
        // Despachar AddVideoMessage para cada playlist añadida
        foreach ($toAdd as $playlistId) {
            $message = new AddVideoMessage(
                $multimediaObject->getId(),
                $playlistId,
                $accountId
            );
            
            $this->messageBus->dispatch($message);
            
            $this->logger->info('[BackofficeListener] AddVideoMessage dispatched', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'playlistId' => $playlistId,
                'accountId' => $accountId,
            ]);
        }
        
        if (count($toRemove) === 0 && count($toAdd) === 0) {
            $this->logger->info('[BackofficeListener] No playlist changes detected, skipping dispatch');
        }
    }

    /**
     * Guarda la configuración de YouTube en las properties del MultimediaObject
     * para facilitar la consulta posterior
     */
    private function saveYoutubeConfigProperties(MultimediaObject $multimediaObject, $request): void
    {
        $accountTagId = $request->request->get('youtube_label');
        $playlistLabels = $request->request->get('youtube_playlist_label', []);

        // Guardar accountId
        if ($accountTagId) {
            $accountTag = $this->documentManager->getRepository(Tag::class)
                ->findOneBy(['_id' => new ObjectId($accountTagId)]);
            
            if ($accountTag) {
                $accountId = $accountTag->getProperty('login');
                if ($accountId) {
                    $multimediaObject->setProperty('youtube_account_id', $accountId);
                }
            }
        }

        // Guardar playlist IDs
        $playlistIds = [];
        foreach ($playlistLabels as $playlistTagId) {
            if ($playlistTagId !== 'any') {
                $playlistIds[] = $playlistTagId;
            }
        }
        
        $multimediaObject->setProperty('youtube_playlists', $playlistIds);
        
        $this->documentManager->flush();
    }

    private function addPlaylistToMultimediaObject(MultimediaObject $multimediaObject, array $multiplePlaylist): void
    {
        foreach ($multiplePlaylist as $playlist) {
            if ('any' !== $playlist) {
                $this->tagService->addTagToMultimediaObject(
                    $multimediaObject,
                    new ObjectId($playlist)
                );
            }
        }
    }
}
