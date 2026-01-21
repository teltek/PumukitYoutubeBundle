<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\AccountHexagonal\Application\Delete\DeleteAccountMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/accounts-hexagonal")
 */
final class DeleteAccountController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/{id}/delete", name="pumukit_youtube_accounts_hexagonal_delete", methods={"DELETE"})
     */
    public function __invoke(string $id): Response
    {
        try {
            // Create and dispatch message to RabbitMQ
            $message = new DeleteAccountMessage(
                accountId: $id
            );

            $this->messageBus->dispatch($message);

            return new JsonResponse([
                'success' => true,
                'message' => 'Account deletion enqueued successfully (HEXAGONAL ASYNC ✅)',
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
