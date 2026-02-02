<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\UI\Backoffice\Controller;

use Pumukit\YoutubeBundle\AccountHexagonal\Application\Create\CreateAccountMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/youtube/accounts")
 */
final class CreateAccountController extends AbstractController
{
    private MessageBusInterface $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    /**
     * @Route("/create", name="pumukit_youtube_accounts_create", methods={"POST"})
     */
    public function __invoke(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['login']) || !isset($data['i18n_title'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Missing required fields: login and i18n_title',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create and dispatch message to RabbitMQ
            $message = new CreateAccountMessage(
                login: $data['login'],
                i18nTitle: $data['i18n_title']
            );

            $this->messageBus->dispatch($message);

            return new JsonResponse([
                'success' => true,
                'message' => 'Account creation enqueued successfully (HEXAGONAL ASYNC ✅)',
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
