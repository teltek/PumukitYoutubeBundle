<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\AccountHexagonal\Application\List\ListAccountsRequest;
use Pumukit\YoutubeBundle\AccountHexagonal\Application\List\ListAccountsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

/**
 * @Route("/admin/youtube/accounts")
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 */
final class AccountsIndexController extends AbstractController
{
    public function __construct(
        private ListAccountsService $listAccountsService
    ) {}

    /**
     * @Route("/", name="pumukit_youtube_accounts_list", methods={"GET"})
     */
    public function __invoke(): Response
    {
        try {
            $request = new ListAccountsRequest();
            $response = $this->listAccountsService->__invoke($request);
            $accounts = $response->getAccounts();

            return $this->render('@PumukitYoutube/AccountHexagonal/Backoffice/index.html.twig', [
                'accounts' => $accounts,
                'total' => count($accounts),
            ]);
        } catch (\Throwable $e) {
            return new Response('<pre>Error: ' . $e->getMessage() . "\n\n" . $e->getTraceAsString() . '</pre>');
        }
    }
}
