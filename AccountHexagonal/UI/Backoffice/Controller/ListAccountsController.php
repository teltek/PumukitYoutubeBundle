<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\AccountHexagonal\Application\List\ListAccountsRequest;
use Pumukit\YoutubeBundle\AccountHexagonal\Application\List\ListAccountsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal controller for rendering account list table.
 * Called via render(controller(...)) from AccountsIndexController.
 * No route annotation - not directly accessible.
 */
final class ListAccountsController extends AbstractController
{
    private ListAccountsService $listAccountsService;

    public function __construct(ListAccountsService $listAccountsService)
    {
        $this->listAccountsService = $listAccountsService;
    }

    /**
     * Renders account list table only (for embedding in main page via render(controller()))
     */
    public function __invoke(): Response
    {
        try {
            $request = new ListAccountsRequest();
            $response = $this->listAccountsService->__invoke($request);
            $accounts = $response->getAccounts();

            return $this->render('@PumukitYoutube/AccountHexagonal/Backoffice/list.html.twig', [
                'accounts' => $accounts,
                'total' => count($accounts),
            ]);
        } catch (\Throwable $e) {
            return new Response('<pre>Error: ' . $e->getMessage() . "\n\n" . $e->getTraceAsString() . '</pre>');
        }
    }
}
