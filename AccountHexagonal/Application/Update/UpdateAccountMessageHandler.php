<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Update;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class UpdateAccountMessageHandler
{
    private UpdateAccountService $updateAccountService;
    private LoggerInterface $logger;

    public function __construct(
        UpdateAccountService $updateAccountService,
        LoggerInterface $logger
    ) {
        $this->updateAccountService = $updateAccountService;
        $this->logger = $logger;
    }

    public function __invoke(UpdateAccountMessage $message): void
    {
        $this->logger->info('[AccountHexagonal] Processing UpdateAccountMessage', [
            'accountId' => $message->getAccountId(),
        ]);

        try {
            // Convert Message to Request
            $request = new UpdateAccountRequest(
                accountId: $message->getAccountId(),
                login: $message->getLogin(),
                i18nTitle: $message->getI18nTitle()
            );

            // Execute service
            $response = $this->updateAccountService->__invoke($request);

            $this->logger->info('[AccountHexagonal] Account updated successfully', [
                'accountId' => $response->getAccount()->getId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AccountHexagonal] Failed to update account', [
                'accountId' => $message->getAccountId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
