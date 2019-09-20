<?php

namespace Pumukit\YoutubeBundle\Controller;

use Pumukit\YoutubeBundle\Document\Youtube;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

/**
 * @Security("is_granted('ROLE_SUPER_ADMIN')")
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

        $youtubeStatusDocuments = $youtubeService->getYoutubeDocumentsByCriteria();
        $statsYoutubeDocuments = $this->processYoutubeDocuments($youtubeStatusDocuments);

        $youtubeStatus = $this->get('doctrine_mongodb.odm.document_manager')->getRepository(Youtube::class)->getAllStatus();

        return [
            'youtubeAccounts' => $youtubeService->getYoutubeAccounts(),
            'accountsStats' => $youtubeService->getAccountsStats(),
            'youtubeStatusDocuments' => $statsYoutubeDocuments,
            'youtubeStatus' => $youtubeStatus,
        ];
    }

    /**
     * @Route("/list/{status}", name="pumukit_youtube_stat_list")
     * @Template("PumukitYoutubeBundle:Stats:template_list.html.twig")
     */
    public function listAction(string $status): array
    {
        $youtubeService = $this->container->get('pumukityoutube.youtube_stats');

        $youtubeStatusDocuments = $youtubeService->getYoutubeDocumentsByCriteria([
            'status' => (int) $status
        ]);

        return [
            'youtubeStatusDocuments' => $youtubeStatusDocuments
        ];
    }

    /**
     * @Route("/configuration/", name="pumukit_youtube_configuration")
     * @Template("PumukitYoutubeBundle:Stats:modal_configuration.html.twig")
     */
    public function modalConfigurationAction(): array
    {
        $youtubeConfigurationService = $this->container->get('pumukityoutube.youtube_configuration');

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

}
