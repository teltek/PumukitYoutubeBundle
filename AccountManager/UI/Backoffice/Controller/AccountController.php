<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountManager\UI\Backoffice\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for managing YouTube accounts in the backoffice.
 */
#[Route('/admin/youtube/account')]
class AccountController extends AbstractController
{
    #[Route('/', name: 'pumukit_youtube_account_list', methods: ['GET'])]
    public function list(): Response
    {
        // TODO: Implement account list
        return $this->render('@PumukitYoutube/Backoffice/account/list.html.twig', [
            'accounts' => [],
        ]);
    }

    #[Route('/new', name: 'pumukit_youtube_account_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        // TODO: Implement account creation
        return $this->render('@PumukitYoutube/Backoffice/account/edit.html.twig', [
            'account' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'pumukit_youtube_account_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        // TODO: Implement account editing
        return $this->render('@PumukitYoutube/Backoffice/account/edit.html.twig', [
            'account' => null,
        ]);
    }

    #[Route('/{id}/pause', name: 'pumukit_youtube_account_pause', methods: ['POST'])]
    public function pause(string $id): Response
    {
        // TODO: Implement account pause
        return $this->redirectToRoute('pumukit_youtube_account_list');
    }

    #[Route('/{id}/resume', name: 'pumukit_youtube_account_resume', methods: ['POST'])]
    public function resume(string $id): Response
    {
        // TODO: Implement account resume
        return $this->redirectToRoute('pumukit_youtube_account_list');
    }

    #[Route('/{id}/delete', name: 'pumukit_youtube_account_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        // TODO: Implement account deletion
        return $this->redirectToRoute('pumukit_youtube_account_list');
    }
}
