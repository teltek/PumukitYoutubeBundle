<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Controller;

use Pumukit\CoreBundle\Services\PaginationService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\YoutubeStatsService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\Request;

/**
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 * @Route("/stats")
 */
class StatsController extends AbstractController
{
    private $youtubeStatsService;
    private $paginationService;

    public function __construct(
        YoutubeStatsService $youtubeStatsService,
        PaginationService $paginationService
    ) {
        $this->youtubeStatsService = $youtubeStatsService;
        $this->paginationService = $paginationService;
    }

    /**
     * @Route("/", name="pumukit_youtube_stat_index")
     */
    public function indexAction(): array
    {
        if (!$this->youtubeStatsService) {
            throw new ServiceNotFoundException('YoutubeStatsService not found');
        }

        $youtubeStatusDocuments = $this->youtubeStatsService->getYoutubeDocumentsByCriteria();
        $statsYoutubeDocuments = $this->processYoutubeDocuments($youtubeStatusDocuments);

        return $this->render('@PumukitYoutube/Stats/template.html.twig', [
            'youtubeAccounts' => $this->youtubeStatsService->getYoutubeAccounts(),
            'accountsStats' => $this->youtubeStatsService->getAccountsStats(),
            'youtubeStatusDocuments' => $statsYoutubeDocuments,
            'youtubeStatus' => Youtube::$statusTexts,
        ]);
    }

    /**
     * @Route("/status/list/{status}", name="pumukit_youtube_stat_list")
     */
    public function listByStatusAction(Request $request, string $status): array
    {
        if (!$this->youtubeStatsService) {
            throw new ServiceNotFoundException('YoutubeService not found');
        }

        $youtubeDocuments = $this->youtubeStatsService->getYoutubeDocumentsByCriteria([
            'status' => (int) $status,
        ]);

        $page = (int) $request->get('page', 1);
        $youtubeDocuments = $this->paginationService->createArrayAdapter($youtubeDocuments, $page, 20);

        return $this->render('@PumukitYoutube/Stats/template_list.html.twig', [
            'youtubeDocuments' => $youtubeDocuments,
            'title' => $this->youtubeStatsService->getTextByStatus($status),
        ]);
    }

    /**
     * @Route("/account/list/{account}", name="pumukit_youtube_stat_account_list")
     */
    public function listByAccountAction(Request $request, string $account): array
    {
        if (!$this->youtubeStatsService) {
            throw new ServiceNotFoundException('YoutubeService not found');
        }

        $youtubeDocuments = $this->youtubeStatsService->getYoutubeDocumentsByCriteria([
            'youtubeAccount' => $account,
        ]);

        $page = (int) $request->get('page', 1);
        $youtubeDocuments = $this->paginationService->createArrayAdapter($youtubeDocuments, $page, 20);

        return $this->render('@PumukitYoutube/Stats/template_list.html.twig', [
            'youtubeDocuments' => $youtubeDocuments,
            'title' => $account,
        ]);
    }

    /**
     * @Route("/configuration/", name="pumukit_youtube_configuration")
     */
    public function modalConfigurationAction(): array
    {
        $youtubeConfigurationService = $this->container->get('pumukityoutube.youtube_configuration');
        if (!$youtubeConfigurationService) {
            throw new ServiceNotFoundException('YoutubeConfigurationService not found');
        }

        return $this->render('@PumukitYoutube/Stats/modal_configuration.html.twig', [
            'youtubeConfiguration' => $youtubeConfigurationService->getBundleConfiguration(),
        ]);
    }

    private function processYoutubeDocuments(array $youtubeStatusDocuments): array
    {
        $stats = [];
        foreach ($youtubeStatusDocuments  as $document) {
            $stats[$document->getStatus()][] = $document;
        }

        return $stats;
    }
}
