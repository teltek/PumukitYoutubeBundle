<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Infrastructure\Controller;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\CreatePlaylistMessage as OldCreatePlaylistMessage;
use Pumukit\YoutubeBundle\Application\Message\Playlist\UpdatePlaylistMessage as OldUpdatePlaylistMessage;
use Pumukit\YoutubeBundle\Application\Message\Playlist\DeletePlaylistMessage as OldDeletePlaylistMessage;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Create\CreatePlaylistMessage;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update\UpdatePlaylistMessage;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete\DeletePlaylistMessage;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

/**
 * @Route("/youtube/playlists")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
class YoutubePlaylistController extends AbstractController
{
    private DocumentManager $documentManager;
    private GoogleClientFactory $googleClientFactory;
    private MessageBusInterface $messageBus;
    private LoggerInterface $logger;

    public function __construct(
        DocumentManager $documentManager,
        GoogleClientFactory $googleClientFactory,
        MessageBusInterface $messageBus,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->googleClientFactory = $googleClientFactory;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
    }

    /**
     * @Route("/", name="pumukit_youtube_playlists_index", methods={"GET"})
     */
    public function indexAction(Request $request): Response
    {
        // Cargar todas las cuentas de YouTube
        $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
        $youtubeAccounts = $accountRepo->findAll();
        
        $accounts = [];
        foreach ($youtubeAccounts as $account) {
            $accounts[] = [
                'id' => $account->getId(),
                'name' => $account->getAccountName(),
                'channelId' => $account->getChannelId(),
            ];
        }
        
        $selectedAccountId = $request->query->get('account');
        $selectedAccount = null;
        $playlists = [];

        if ($selectedAccountId && count($accounts) > 0) {
            // Find the selected account object
            $account = $accountRepo->find($selectedAccountId);
            
            if ($account) {
                $selectedAccount = [
                    'id' => $account->getId(),
                    'name' => $account->getAccountName(),
                    'channelId' => $account->getChannelId(),
                ];
                
                try {
                    // Obtener playlists desde MongoDB (más rápido, no consume quota)
                    $playlistRepo = $this->documentManager->getRepository(YoutubePlaylist::class);
                    $mongoPlaylists = $playlistRepo->findBy(['accountId' => $selectedAccountId]);
                    
                    // Convert to array format expected by template
                    foreach ($mongoPlaylists as $playlist) {
                        $playlists[] = [
                            'id' => $playlist->getYoutubeId(),
                            'snippet' => [
                                'title' => $playlist->getTitle(),
                                'description' => $playlist->getDescription() ?? '',
                                'publishedAt' => $playlist->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                                'thumbnails' => [
                                    'default' => [
                                        'url' => 'https://i.ytimg.com/img/no_thumbnail.jpg'
                                    ]
                                ],
                            ],
                            'status' => [
                                'privacyStatus' => $playlist->getPrivacy()
                            ],
                            'contentDetails' => [
                                'itemCount' => $playlist->getVideoCount()
                            ],
                        ];
                    }
                    
                    $this->logger->info('[YoutubePlaylistController] Loaded playlists from MongoDB', [
                        'accountId' => $selectedAccountId,
                        'playlistCount' => count($playlists),
                    ]);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Error loading playlists: ' . $e->getMessage());
                    $this->logger->error('[YoutubePlaylistController] Error loading playlists from MongoDB', [
                        'accountId' => $selectedAccountId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        return $this->render('@PumukitNewAdmin/YoutubePlaylist/index.html.twig', [
            'accounts' => $accounts,
            'selectedAccount' => $selectedAccount,
            'playlists' => $playlists,
        ]);
    }

    /**
     * @Route("/create", name="pumukit_youtube_playlists_create", methods={"POST"})
     */
    public function createAction(Request $request): JsonResponse
    {
        try {
            $accountId = $request->request->get('accountId');
            $title = $request->request->get('title');
            $description = $request->request->get('description', '');
            $privacy = $request->request->get('privacy', 'unlisted');

            if (!$accountId || !$title) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Account ID and title are required',
                ], 400);
            }

            $this->logger->info('[YoutubePlaylistController] Queueing playlist creation', [
                'accountId' => $accountId,
                'title' => $title,
            ]);

            // Verify account exists
            $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
            $account = $accountRepo->find($accountId);
            
            if (!$account) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Account not found',
                ], 404);
            }

            // Dispatch async message (using Hexagonal Architecture)
            $message = new CreatePlaylistMessage(
                $accountId,
                $title,
                $description,
                $privacy
            );

            $this->messageBus->dispatch($message);
            
            $this->logger->info('[YoutubePlaylistController] Playlist creation queued (Hexagonal)', [
                'accountId' => $accountId,
                'title' => $title,
            ]);
                
            return new JsonResponse([
                'success' => true,
                'message' => 'Playlist creation queued successfully. It will be processed asynchronously.',
                'queued' => true,
                'status' => 'enqueued',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[YoutubePlaylistController] Error queueing playlist creation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @Route("/{playlistId}/update", name="pumukit_youtube_playlists_update", methods={"POST"})
     */
    public function updateAction(string $playlistId, Request $request): JsonResponse
    {
        error_log("========== UPDATE PLAYLIST CALLED ==========");
        error_log("PlaylistId: " . $playlistId);
        error_log("All request data: " . print_r($request->request->all(), true));
        
        try {
            $accountId = $request->request->get('accountId');
            $title = $request->request->get('title');
            $description = $request->request->get('description');
            $privacy = $request->request->get('privacy');

            error_log("AccountId: " . $accountId);
            error_log("Title: " . $title);
            error_log("Privacy: " . $privacy);

            if (!$accountId) {
                error_log("ERROR: No accountId provided");
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Account ID is required',
                ], 400);
            }

            $this->logger->info('[YoutubePlaylistController] Queueing playlist update', [
                'playlistId' => $playlistId,
                'accountId' => $accountId,
                'title' => $title,
                'privacy' => $privacy,
            ]);

            // Verify account exists
            $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
            $account = $accountRepo->find($accountId);
            
            if (!$account) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Account not found',
                ], 404);
            }

            // Prepare update data
            $updateData = [];
            if ($title !== null) {
                $updateData['title'] = $title;
            }
            if ($description !== null) {
                $updateData['description'] = $description;
            }
            if ($privacy !== null) {
                $updateData['privacy'] = $privacy;
            }

            if (empty($updateData)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'No update data provided',
                ], 400);
            }

            // Dispatch async message (using Hexagonal Architecture)
            $message = new UpdatePlaylistMessage(
                $playlistId,
                $title ?? '',
                $description ?? '',
                $privacy ?? 'unlisted'
            );

            error_log("========== ABOUT TO DISPATCH UPDATE MESSAGE (HEXAGONAL) ==========");
            error_log("Message class: " . get_class($message));
            error_log("PlaylistId: " . $playlistId);

            $this->messageBus->dispatch($message);
            
            error_log("========== MESSAGE DISPATCHED SUCCESSFULLY ==========");
            
            $this->logger->info('[YoutubePlaylistController] Playlist update queued (Hexagonal)', [
                'playlistId' => $playlistId,
                'accountId' => $accountId,
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Playlist update queued successfully. It will be processed asynchronously.',
                'queued' => true,
                'status' => 'enqueued',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[YoutubePlaylistController] Error queueing playlist update', [
                'playlistId' => $playlistId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @Route("/{playlistId}/delete", name="pumukit_youtube_playlists_delete", methods={"POST"})
     */
    public function deleteAction(string $playlistId, Request $request): JsonResponse
    {
        try {
            $accountId = $request->request->get('accountId');

            if (!$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Account ID is required',
                ], 400);
            }

            $this->logger->info('[YoutubePlaylistController] Queueing playlist deletion', [
                'playlistId' => $playlistId,
                'accountId' => $accountId,
            ]);

            // Verify account exists
            $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
            $account = $accountRepo->find($accountId);
            
            if (!$account) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Account not found',
                ], 404);
            }

            // Dispatch async message (using Hexagonal Architecture)
            $message = new DeletePlaylistMessage($playlistId);

            $this->messageBus->dispatch($message);
            
            $this->logger->info('[YoutubePlaylistController] Playlist deletion queued (Hexagonal)', [
                'playlistId' => $playlistId,
                'accountId' => $accountId,
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Playlist deletion queued successfully. It will be processed asynchronously.',
                'queued' => true,
                'status' => 'enqueued',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[YoutubePlaylistController] Error queueing playlist deletion', [
                'playlistId' => $playlistId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @Route("/{playlistId}/videos", name="pumukit_youtube_playlists_videos", methods={"GET"})
     */
    public function videosAction(string $playlistId, Request $request): JsonResponse
    {
        try {
            $accountId = $request->query->get('accountId');

            if (!$accountId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Account ID is required',
                ], 400);
            }

            $this->logger->info('[YoutubePlaylistController] Loading playlist videos', [
                'playlistId' => $playlistId,
                'accountId' => $accountId,
            ]);

            // Obtener la cuenta de YouTube
            $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
            $account = $accountRepo->find($accountId);
            
            if (!$account) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Account not found',
                ], 404);
            }

            // Crear cliente Google y servicio YouTube
            $client = $this->googleClientFactory->createClient($account);
            $youtubeService = new \Google_Service_YouTube($client);
            
            // Obtener items de la playlist
            $playlistItemsResponse = $youtubeService->playlistItems->listPlaylistItems('snippet', [
                'playlistId' => $playlistId,
                'maxResults' => 50
            ]);
            
            // Convert to array format
            $videos = [];
            foreach ($playlistItemsResponse->getItems() as $item) {
                $videos[] = [
                    'snippet' => [
                        'title' => $item->getSnippet()->getTitle(),
                        'description' => $item->getSnippet()->getDescription() ?? '',
                        'thumbnails' => [
                            'default' => [
                                'url' => $item->getSnippet()->getThumbnails()->getDefault()->getUrl() ?? 'https://i.ytimg.com/img/no_thumbnail.jpg'
                            ]
                        ],
                        'resourceId' => [
                            'videoId' => $item->getSnippet()->getResourceId()->getVideoId()
                        ]
                    ]
                ];
            }

            return new JsonResponse([
                'success' => true,
                'videos' => $videos,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[YoutubePlaylistController] Error loading playlist videos', [
                'playlistId' => $playlistId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
