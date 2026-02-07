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
            'name' => $message->getName(),
            'credentialsPath' => $message->getCredentialsPath(),
        ]);

        try {
            // Convert Message to Request
            $request = new CreateAccountRequest(
                name: $message->getName(),
                credentialsPath: $message->getCredentialsPath()
            );

            // Execute service
            $response = $this->createAccountService->__invoke($request);

            $this->logger->info('[AccountHexagonal] Account created successfully', [
                'accountId' => $response->getAccount()->getId(),
                'name' => $message->getName(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AccountHexagonal] Failed to create account', [
                'name' => $message->getName(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
