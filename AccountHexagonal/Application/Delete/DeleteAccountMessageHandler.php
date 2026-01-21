<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Delete;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class DeleteAccountMessageHandler
{
    private DeleteAccountService $deleteAccountService;
    private LoggerInterface $logger;

    public function __construct(
        DeleteAccountService $deleteAccountService,
        LoggerInterface $logger
    ) {
        $this->deleteAccountService = $deleteAccountService;
        $this->logger = $logger;
    }

    public function __invoke(DeleteAccountMessage $message): void
    {
        $this->logger->info('[AccountHexagonal] Processing DeleteAccountMessage', [
            'accountId' => $message->getAccountId(),
        ]);

        try {
            // Convert Message to Request
            $request = new DeleteAccountRequest(
                accountId: $message->getAccountId()
            );

            // Execute service
            $response = $this->deleteAccountService->__invoke($request);

            $this->logger->info('[AccountHexagonal] Account deleted successfully', [
                'accountId' => $response->getAccountId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AccountHexagonal] Failed to delete account', [
                'accountId' => $message->getAccountId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
