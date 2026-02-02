<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\QuotaHexagonal\Application\Waiting;

use Psr\Log\LoggerInterface;

class WaitingMessageHandler
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(WaitingMessage $message): void
    {
        $this->logger->info('[WaitingMessageHandler] Message waiting for quota reset', [
            'accountId' => $message->getAccountId(),
            'originalMessageClass' => $message->getOriginalMessageClassName(),
            'operation' => $message->getOperation(),
            'quotaCost' => $message->getQuotaCost(),
            'priority' => $message->getPriority(),
            'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
            'waitingTime' => $message->getCreatedAt()->diff(new \DateTimeImmutable())->format('%h hours %i minutes'),
        ]);

        // NO hacer nada - el mensaje permanece en la cola hasta que se recupere
        // El comando youtube:quota:recover-waiting-messages se encargará de moverlos
    }
}
