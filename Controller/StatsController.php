<?php

namespace Pumukit\YoutubeBundle\Controller;

use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Pumukit\YoutubeBundle\Document\Youtube;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\Request;

/**
 * @Security("is_granted('ROLE_ACCESS_YOUTUBE')")
 * @Route("/stats")
 */
class StatsController extends Controller
{
    /**
     * @Route("/", name="pumukit_youtube_stat_index")
     * @Template("PumukitYoutubeBundle:Stats:template.html.twig")
     */
    public function indexAction(): array
    {
        $youtubeService = $this->container->get('pumukityoutube.youtube_stats');
        if (!$youtubeService) {
            throw new ServiceNotFoundException('YoutubeService not found');
        }

        $youtubeStatusDocuments = $youtubeService->getYoutubeDocumentsByCriteria();
        $statsYoutubeDocuments = $this->processYoutubeDocuments($youtubeStatusDocuments);

        $youtubeStatus = Youtube::$statusTexts;

        return [
            'youtubeAccounts' => $youtubeService->getYoutubeAccounts(),
            'accountsStats' => $youtubeService->getAccountsStats(),
            'youtubeStatusDocuments' => $statsYoutubeDocuments,
            'youtubeStatus' => $youtubeStatus,
        ];
    }

    /**
     * @Route("/status/list/{status}", name="pumukit_youtube_stat_list")
     * @Template("PumukitYoutubeBundle:Stats:template_list.html.twig")
     */
    public function listByStatusAction(Request $request, string $status): array
    {
        $youtubeService = $this->container->get('pumukityoutube.youtube_stats');
        if (!$youtubeService) {
            throw new ServiceNotFoundException('YoutubeService not found');
        }

        $criteria = [
            'status' => (int) $status,
        ];

        $youtubeDocuments = $youtubeService->getYoutubeDocumentsByCriteria($criteria);

        $page = (int) $request->get('page', 1);
        $youtubeDocuments = $this->createPager($youtubeDocuments, $page, 20);

        return [
            'youtubeDocuments' => $youtubeDocuments,
            'title' => $youtubeService->getTextByStatus($status),
        ];
    }

    /**
     * @Route("/account/list/{account}", name="pumukit_youtube_stat_account_list")
     * @Template("PumukitYoutubeBundle:Stats:template_list.html.twig")
     */
    public function listByAccountAction(Request $request, string $account): array
    {
        $youtubeService = $this->container->get('pumukityoutube.youtube_stats');
        if (!$youtubeService) {
            throw new ServiceNotFoundException('YoutubeService not found');
        }

        $criteria = [
            'youtubeAccount' => $account,
        ];

        $youtubeDocuments = $youtubeService->getYoutubeDocumentsByCriteria($criteria);

        $page = (int) $request->get('page', 1);
        $youtubeDocuments = $this->createPager($youtubeDocuments, $page, 20);

        return [
            'youtubeDocuments' => $youtubeDocuments,
            'title' => $account,
        ];
    }

    /**
     * @Route("/configuration/", name="pumukit_youtube_configuration")
     * @Template("PumukitYoutubeBundle:Stats:modal_configuration.html.twig")
     */
    public function modalConfigurationAction(): array
    {
        $youtubeConfigurationService = $this->container->get('pumukityoutube.youtube_configuration');
        if (!$youtubeConfigurationService) {
            throw new ServiceNotFoundException('YoutubeConfigurationService not found');
        }

        return [
            'youtubeConfiguration' => $youtubeConfigurationService->getBundleConfiguration(),
        ];
    }

    private function processYoutubeDocuments(array $youtubeStatusDocuments): array
    {
        $stats = [];
        foreach ($youtubeStatusDocuments  as $document) {
            $stats[$document->getStatus()][] = $document;
        }

        return $stats;
    }

    private function createPager($objects, $page, $limit = 10): Pagerfanta
    {
        $adapter = new ArrayAdapter($objects);
        $pager = new Pagerfanta($adapter);
        $pager->setMaxPerPage($limit)->setNormalizeOutOfRangePages(true)->setCurrentPage($page);

        return $pager;
    }
}
