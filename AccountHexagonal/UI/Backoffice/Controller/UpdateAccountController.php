<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\AccountHexagonal\Application\Update\UpdateAccountMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/accounts-hexagonal")
 */
final class UpdateAccountController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/{id}/update", name="pumukit_youtube_accounts_hexagonal_update", methods={"PUT", "PATCH"})
     */
    public function __invoke(string $id, Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);

            // Create and dispatch message to RabbitMQ
            $message = new UpdateAccountMessage(
                accountId: $id,
                login: $data['login'] ?? null,
                i18nTitle: $data['i18n_title'] ?? null
            );

            $this->messageBus->dispatch($message);

            return new JsonResponse([
                'success' => true,
                'message' => 'Account update enqueued successfully (HEXAGONAL ASYNC ✅)',
                'status' => 'queued',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
