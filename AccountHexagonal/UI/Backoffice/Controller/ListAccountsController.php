<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\AccountHexagonal\Application\List\ListAccountsRequest;
use Pumukit\YoutubeBundle\AccountHexagonal\Application\List\ListAccountsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/accounts-hexagonal")
 */
final class ListAccountsController extends AbstractController
{
    private ListAccountsService $listAccountsService;

    public function __construct(ListAccountsService $listAccountsService)
    {
        $this->listAccountsService = $listAccountsService;
    }

    /**
     * @Route("/", name="pumukit_youtube_accounts_hexagonal_list", methods={"GET"})
     */
    public function __invoke(): Response
    {
        try {
            $request = new ListAccountsRequest();
            $response = $this->listAccountsService->__invoke($request);

            return new JsonResponse([
                'success' => true,
                'accounts' => $response->toArray(),
                'total' => count($response->getAccounts()),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
