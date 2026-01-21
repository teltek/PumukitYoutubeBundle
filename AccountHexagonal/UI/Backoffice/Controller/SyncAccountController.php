<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\AccountHexagonal\Application\Sync\SyncAccountMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/accounts-hexagonal")
 */
final class SyncAccountController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/{id}/sync", name="pumukit_youtube_accounts_hexagonal_sync", methods={"POST"})
     */
    public function __invoke(string $id, Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['channel_id'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing required field: channel_id',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create and dispatch message to RabbitMQ
            $message = new SyncAccountMessage(
                accountId: $id,
                channelId: $data['channel_id']
            );

            $this->messageBus->dispatch($message);

            return new JsonResponse([
                'success' => true,
                'message' => 'Account sync enqueued successfully (HEXAGONAL ASYNC ✅)',
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
