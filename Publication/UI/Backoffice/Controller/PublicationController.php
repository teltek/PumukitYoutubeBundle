<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\UI\Backoffice\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for managing YouTube publications in the backoffice.
 */
#[Route('/admin/youtube/publication')]
class PublicationController extends AbstractController
{
    #[Route('/', name: 'pumukit_youtube_publication_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filter = $request->query->get('filter', 'all');
        
        // TODO: Implement publication list with filters
        return $this->render('@PumukitYoutube/Backoffice/publication/list.html.twig', [
            'publications' => [],
            'filter' => $filter,
        ]);
    }

    #[Route('/new', name: 'pumukit_youtube_publication_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // TODO: Implement publication creation form
        return $this->render('@PumukitYoutube/Backoffice/publication/form.html.twig', [
            'publication' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'pumukit_youtube_publication_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        // TODO: Implement publication editing
        return $this->render('@PumukitYoutube/Backoffice/publication/form.html.twig', [
            'publication' => null,
        ]);
    }

    #[Route('/{id}/retry', name: 'pumukit_youtube_publication_retry', methods: ['POST'])]
    public function retry(string $id): Response
    {
        // TODO: Implement retry logic
        return $this->redirectToRoute('pumukit_youtube_publication_list');
    }

    #[Route('/{id}/delete', name: 'pumukit_youtube_publication_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        // TODO: Implement publication deletion
        return $this->redirectToRoute('pumukit_youtube_publication_list');
    }

    #[Route('/errors', name: 'pumukit_youtube_publication_errors', methods: ['GET'])]
    public function errors(): Response
    {
        // TODO: Implement error list
        return $this->render('@PumukitYoutube/Backoffice/publication/list.html.twig', [
            'publications' => [],
            'filter' => 'errors',
        ]);
    }
}
