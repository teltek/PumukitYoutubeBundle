<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Infrastructure\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Application\Message\Playlist\AssignToPlaylistsMessage;
use Pumukit\YoutubeBundle\Document\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Pumukit\YoutubeBundle\Message\DeleteYoutubeVideoMessage;
use Pumukit\YoutubeBundle\Message\MoveVideoToPlaylistMessage;
use Pumukit\YoutubeBundle\Message\UpdateYoutubeVideoMessage;
use Pumukit\YoutubeBundle\Message\UploadYoutubeCaptionsMessage;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/youtube/video', name: 'pumukit_youtube_video_')]
#[Security("is_granted('ROLE_ACCESS_YOUTUBE')")]
class YoutubeVideoManagementController extends AbstractController
{
    private DocumentManager $documentManager;
    private YoutubeEventService $youtubeEventService;
    private MessageBusInterface $messageBus;
    private GoogleClientFactory $clientFactory;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeEventService $youtubeEventService,
        MessageBusInterface $messageBus,
        GoogleClientFactory $clientFactory
    ) {
        $this->documentManager = $documentManager;
        $this->youtubeEventService = $youtubeEventService;
        $this->messageBus = $messageBus;
        $this->clientFactory = $clientFactory;
    }

    /**
     * Show YouTube management panel for a MultimediaObject.
     */
    #[Route('/panel/{id}', name: 'panel', methods: ['GET'])]
    public function panelAction(string $id): Response
    {
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find(new ObjectId($id));

        if (!$multimediaObject) {
            throw new NotFoundHttpException('MultimediaObject not found');
        }

        $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
        $accountId = $multimediaObject->getProperty('youtube_account_id');

        if (!$youtubeVideoId || !$accountId) {
            return $this->render('@PumukitYoutube/VideoManagement/not_uploaded.html.twig', [
                'multimediaObject' => $multimediaObject,
            ]);
        }

        $account = $this->documentManager->getRepository(YoutubeAccount::class)->find($accountId);
        if (!$account) {
            return $this->render('@PumukitYoutube/VideoManagement/error.html.twig', [
                'multimediaObject' => $multimediaObject,
                'error' => 'YouTube account not found',
            ]);
        }

        // Get playlists for the account
        try {
            $client = $this->clientFactory->createClient($account);
            $youtube = new \Google_Service_YouTube($client);
            
            $playlistsResponse = $youtube->playlists->listPlaylists('snippet', [
                'mine' => true,
                'maxResults' => 50,
            ]);
            
            $playlists = [];
            foreach ($playlistsResponse->getItems() as $playlist) {
                $playlists[] = [
                    'id' => $playlist->getId(),
                    'title' => $playlist->getSnippet()->getTitle(),
                ];
            }
        } catch (\Exception $e) {
            $playlists = [];
        }

        return $this->render('@PumukitYoutube/VideoManagement/panel.html.twig', [
            'multimediaObject' => $multimediaObject,
            'youtubeVideoId' => $youtubeVideoId,
            'account' => $account,
            'playlists' => $playlists,
        ]);
    }

    /**
     * Update YouTube video metadata.
     */
    #[Route('/update/{id}', name: 'update', methods: ['POST'])]
    public function updateAction(Request $request, string $id): JsonResponse
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find(new ObjectId($id));

            if (!$multimediaObject) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'MultimediaObject not found',
                ], 404);
            }

            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            $accountId = $multimediaObject->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Video is not uploaded to YouTube',
                ], 400);
            }

            $metadata = [];
            
            if ($request->request->has('title')) {
                $metadata['title'] = $request->request->get('title');
            }
            if ($request->request->has('description')) {
                $metadata['description'] = $request->request->get('description');
            }
            if ($request->request->has('privacy')) {
                $metadata['privacy'] = $request->request->get('privacy');
            }
            if ($request->request->has('tags')) {
                $tagsString = $request->request->get('tags');
                $metadata['tags'] = array_map('trim', explode(',', $tagsString));
            }

            $syncFromMM = $request->request->getBoolean('sync_from_mm', false);
            $async = $request->request->getBoolean('async', false);

            if ($async) {
                $message = new UpdateYoutubeVideoMessage(
                    $multimediaObject->getId(),
                    $accountId,
                    $youtubeVideoId,
                    $metadata,
                    $syncFromMM
                );
                $this->messageBus->dispatch($message);

                return new JsonResponse([
                    'success' => true,
                    'message' => 'Update queued successfully. Processing in background.',
                ]);
            }

            $account = $this->documentManager->getRepository(YoutubeAccount::class)->find($accountId);
            $this->youtubeEventService->updateVideoOnYoutube(
                $multimediaObject,
                $account,
                $youtubeVideoId,
                $metadata,
                $syncFromMM
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Video updated successfully on YouTube',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error updating video: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete YouTube video.
     */
    #[Route('/delete/{id}', name: 'delete', methods: ['POST'])]
    public function deleteAction(Request $request, string $id): JsonResponse
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find(new ObjectId($id));

            if (!$multimediaObject) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'MultimediaObject not found',
                ], 404);
            }

            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            $accountId = $multimediaObject->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Video is not uploaded to YouTube',
                ], 400);
            }

            $async = $request->request->getBoolean('async', false);

            if ($async) {
                $message = new DeleteYoutubeVideoMessage(
                    $multimediaObject->getId(),
                    $accountId,
                    $youtubeVideoId
                );
                $this->messageBus->dispatch($message);

                return new JsonResponse([
                    'success' => true,
                    'message' => 'Deletion queued successfully. Processing in background.',
                ]);
            }

            $account = $this->documentManager->getRepository(YoutubeAccount::class)->find($accountId);
            $this->youtubeEventService->deleteFromYoutube(
                $multimediaObject,
                $account,
                $youtubeVideoId
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Video deleted successfully from YouTube',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting video: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload captions to YouTube video.
     */
    #[Route('/captions/upload/{id}', name: 'upload_captions', methods: ['POST'])]
    public function uploadCaptionsAction(Request $request, string $id): JsonResponse
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find(new ObjectId($id));

            if (!$multimediaObject) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'MultimediaObject not found',
                ], 404);
            }

            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            $accountId = $multimediaObject->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Video is not uploaded to YouTube',
                ], 400);
            }

            $file = $request->files->get('caption_file');
            if (!$file) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No caption file provided',
                ], 400);
            }

            $language = $request->request->get('language', 'en');
            $name = $request->request->get('name', 'Subtitles');
            $isDraft = $request->request->getBoolean('is_draft', false);
            $async = $request->request->getBoolean('async', false);

            // Save file temporarily
            $uploadDir = sys_get_temp_dir().'/pumukit_captions';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = uniqid('caption_').'.'.$file->getClientOriginalExtension();
            $filePath = $uploadDir.'/'.$fileName;
            $file->move($uploadDir, $fileName);

            if ($async) {
                $message = new UploadYoutubeCaptionsMessage(
                    $multimediaObject->getId(),
                    $accountId,
                    $youtubeVideoId,
                    $filePath,
                    $language,
                    $name,
                    $isDraft
                );
                $this->messageBus->dispatch($message);

                return new JsonResponse([
                    'success' => true,
                    'message' => 'Caption upload queued successfully. Processing in background.',
                ]);
            }

            $account = $this->documentManager->getRepository(YoutubeAccount::class)->find($accountId);
            $this->youtubeEventService->uploadCaptionsToYoutube(
                $multimediaObject,
                $account,
                $youtubeVideoId,
                $filePath,
                $language,
                $name,
                $isDraft
            );

            // Clean up temporary file
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Captions uploaded successfully to YouTube',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error uploading captions: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move video to a different playlist.
     */
    #[Route('/playlist/move/{id}', name: 'move_playlist', methods: ['POST'])]
    public function moveToPlaylistAction(Request $request, string $id): JsonResponse
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find(new ObjectId($id));

            if (!$multimediaObject) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'MultimediaObject not found',
                ], 404);
            }

            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            $accountId = $multimediaObject->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Video is not uploaded to YouTube',
                ], 400);
            }

            $fromPlaylist = $request->request->get('from_playlist');
            $toPlaylist = $request->request->get('to_playlist');
            $async = $request->request->getBoolean('async', false);

            if (!$toPlaylist) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Target playlist is required',
                ], 400);
            }

            if ($async) {
                $message = new MoveVideoToPlaylistMessage(
                    $multimediaObject->getId(),
                    $accountId,
                    $youtubeVideoId,
                    $fromPlaylist,
                    $toPlaylist
                );
                $this->messageBus->dispatch($message);

                return new JsonResponse([
                    'success' => true,
                    'message' => 'Playlist move queued successfully. Processing in background.',
                ]);
            }

            $account = $this->documentManager->getRepository(YoutubeAccount::class)->find($accountId);
            
            if ($fromPlaylist) {
                $this->youtubeEventService->moveVideoBetweenPlaylists(
                    $multimediaObject,
                    $account,
                    $youtubeVideoId,
                    $fromPlaylist,
                    $toPlaylist
                );
                $message = 'Video moved successfully between playlists';
            } else {
                $this->youtubeEventService->addVideoToSinglePlaylist(
                    $multimediaObject,
                    $account,
                    $youtubeVideoId,
                    $toPlaylist
                );
                $message = 'Video added successfully to playlist';
            }

            return new JsonResponse([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error managing playlist: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add video to playlist.
     */
    #[Route('/playlist/add/{id}', name: 'add_to_playlist', methods: ['POST'])]
    public function addToPlaylistAction(Request $request, string $id): JsonResponse
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find(new ObjectId($id));

            if (!$multimediaObject) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'MultimediaObject not found',
                ], 404);
            }

            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            $accountId = $multimediaObject->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Video is not uploaded to YouTube',
                ], 400);
            }

            $playlistId = $request->request->get('playlist_id');
            if (!$playlistId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Playlist ID is required',
                ], 400);
            }

            $account = $this->documentManager->getRepository(YoutubeAccount::class)->find($accountId);
            $this->youtubeEventService->addVideoToSinglePlaylist(
                $multimediaObject,
                $account,
                $youtubeVideoId,
                $playlistId
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Video added successfully to playlist',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error adding video to playlist: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove video from playlist.
     */
    #[Route('/playlist/remove/{id}', name: 'remove_from_playlist', methods: ['POST'])]
    public function removeFromPlaylistAction(Request $request, string $id): JsonResponse
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find(new ObjectId($id));

            if (!$multimediaObject) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'MultimediaObject not found',
                ], 404);
            }

            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            $accountId = $multimediaObject->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Video is not uploaded to YouTube',
                ], 400);
            }

            $playlistId = $request->request->get('playlist_id');
            if (!$playlistId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Playlist ID is required',
                ], 400);
            }

            $account = $this->documentManager->getRepository(YoutubeAccount::class)->find($accountId);
            $this->youtubeEventService->removeVideoFromPlaylist(
                $multimediaObject,
                $account,
                $youtubeVideoId,
                $playlistId
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Video removed successfully from playlist',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error removing video from playlist: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign video to multiple playlists.
     */
    #[Route('/playlists/assign/{id}', name: 'assign_playlists', methods: ['POST'])]
    public function assignPlaylistsAction(Request $request, string $id): JsonResponse
    {
        try {
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find(new ObjectId($id));

            if (!$multimediaObject) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'MultimediaObject not found',
                ], 404);
            }

            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            $accountId = $multimediaObject->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Video not published on YouTube',
                ], 400);
            }

            $data = json_decode($request->getContent(), true);
            $playlistIds = $data['playlist_ids'] ?? [];

            if (empty($playlistIds)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No playlists selected',
                ], 400);
            }

            // Dispatch async message for playlist assignment
            $this->messageBus->dispatch(new AssignToPlaylistsMessage($id, $playlistIds, $accountId));

            return new JsonResponse([
                'success' => true,
                'message' => 'Playlist assignment queued. It will be processed asynchronously.',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error assigning playlists: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * List all playlists for an account.
     */
    #[Route('/playlists/list/{accountId}', name: 'playlists_list', methods: ['GET'])]
    public function listPlaylistsAction(string $accountId): JsonResponse
    {
        try {
            $playlists = $this->documentManager
                ->createQueryBuilder(\Pumukit\YoutubeBundle\Domain\Model\YoutubePlaylist::class)
                ->field('accountId')->equals($accountId)
                ->sort('title', 'ASC')
                ->getQuery()
                ->execute();

            $playlistsData = [];
            foreach ($playlists as $playlist) {
                $playlistsData[] = [
                    'id' => $playlist->getYoutubeId(),
                    'title' => $playlist->getTitle(),
                    'description' => $playlist->getDescription(),
                    'videoCount' => $playlist->getVideoCount(),
                    'privacy' => $playlist->getPrivacy(),
                ];
            }

            return new JsonResponse([
                'success' => true,
                'playlists' => $playlistsData,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error loading playlists: '.$e->getMessage(),
            ], 500);
        }
    }
}
