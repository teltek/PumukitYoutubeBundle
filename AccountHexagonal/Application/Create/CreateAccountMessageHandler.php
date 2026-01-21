<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class CreateAccountMessageHandler
{
    private CreateAccountService $createAccountService;
    private LoggerInterface $logger;

    public function __construct(
        CreateAccountService $createAccountService,
        LoggerInterface $logger
    ) {
        $this->createAccountService = $createAccountService;
        $this->logger = $logger;
    }

    public function __invoke(CreateAccountMessage $message): void
    {
        $this->logger->info('[AccountHexagonal] Processing CreateAccountMessage', [
            'login' => $message->getLogin(),
        ]);

        try {
            // Convert Message to Request
            $request = new CreateAccountRequest(
                login: $message->getLogin(),
                i18nTitle: $message->getI18nTitle()
            );

            // Execute service
            $response = $this->createAccountService->__invoke($request);

            $this->logger->info('[AccountHexagonal] Account created successfully', [
                'accountId' => $response->getAccount()->getId(),
                'login' => $message->getLogin(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AccountHexagonal] Failed to create account', [
                'login' => $message->getLogin(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
