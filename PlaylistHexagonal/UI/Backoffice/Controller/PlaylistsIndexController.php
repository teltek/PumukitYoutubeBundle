<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List\ListPlaylistRequest;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List\ListPlaylistService;
use Pumukit\YoutubeBundle\AccountHexagonal\Application\List\ListAccountsRequest;
use Pumukit\YoutubeBundle\AccountHexagonal\Application\List\ListAccountsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

/**
 * @Route("/admin/youtube/playlists")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
final class PlaylistsIndexController extends AbstractController
{
    public function __construct(
        private ListPlaylistService $listPlaylistService,
        private ListAccountsService $listAccountsService
    ) {}

    /**
     * @Route("/", name="pumukit_youtube_playlists_index", methods={"GET"})
     */
    public function __invoke(Request $request): Response
    {
        try {
            // Get all accounts
            $accountRequest = new ListAccountsRequest();
            $accountResponse = $this->listAccountsService->__invoke($accountRequest);
            $accounts = $accountResponse->getAccounts();

            // Get selected account ID from query parameter
            $selectedAccountId = $request->query->get('account');
            $selectedAccount = null;
            $playlists = [];

            // If an account is selected, get its playlists
            if ($selectedAccountId) {
                foreach ($accounts as $account) {
                    if ($account->getId() === $selectedAccountId) {
                        $selectedAccount = $account;
                        break;
                    }
                }

                if ($selectedAccount) {
                    $listRequest = new ListPlaylistRequest($selectedAccountId);
                    $response = $this->listPlaylistService->__invoke($listRequest);
                    $playlists = $response->playlists;
                }
            }

            return $this->render('@PumukitYoutube/PlaylistHexagonal/Backoffice/index.html.twig', [
                'accounts' => $accounts,
                'selectedAccount' => $selectedAccount,
                'playlists' => $playlists,
            ]);
        } catch (\Throwable $e) {
            return new Response('<pre>Error: ' . $e->getMessage() . "\n\n" . $e->getTraceAsString() . '</pre>');
        }
    }
}
