<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Infrastructure\Controller;

use Pumukit\YoutubeBundle\Application\Query\GetQuotaStatusQuery;
use Pumukit\YoutubeBundle\Application\Query\GetQuotaStatusQueryHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/youtube/quota', name: 'pumukit_youtube_quota_')]
final class QuotaController extends AbstractController
{
    public function __construct(
        private readonly GetQuotaStatusQueryHandler $quotaStatusQueryHandler
    ) {}

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $accountId = $request->query->get('account');

        $query = new GetQuotaStatusQuery($accountId);
        $data = ($this->quotaStatusQueryHandler)($query);

        // Si es una sola cuenta, convertir a array para la vista
        if ($accountId && isset($data['account'])) {
            $data = [$data];
        }

        return $this->render('@PumukitYoutube/UI/View/quota_index.html.twig', [
            'accounts_quota' => $data,
            'selected_account' => $accountId,
        ]);
    }

    #[Route('/api', name: 'api', methods: ['GET'])]
    public function api(Request $request): Response
    {
        $accountId = $request->query->get('account');

        $query = new GetQuotaStatusQuery($accountId);
        $data = ($this->quotaStatusQueryHandler)($query);

        return $this->json($data);
    }
}
