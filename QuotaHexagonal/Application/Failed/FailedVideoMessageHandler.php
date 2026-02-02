<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\QuotaHexagonal\Application\Failed;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Shared\Domain\Model\FailedPublication;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler para videos que fallaron permanentemente
 * (no se reintentarán, necesitan intervención manual)
 */
#[AsMessageHandler]
final class FailedVideoMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(FailedVideoMessage $message): void
    {
        $this->logger->warning('[FailedVideoMessageHandler] Processing failed video', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'accountId' => $message->getAccountId(),
            'errorMessage' => $message->getErrorMessage(),
            'httpStatusCode' => $message->getHttpStatusCode(),
            'operation' => $message->getOperation(),
            'reason' => $message->getReason(),
        ]);

        try {
            // Crear registro de fallo permanente
            $failedPublication = FailedPublication::create(
                multimediaObjectId: $message->getMultimediaObjectId(),
                youtubeAccountId: $message->getAccountId(),
                errorMessage: $message->getErrorMessage(),
                operation: $message->getOperation() ?? 'video.upload',
                errorDetails: $message->getErrorDetails(),
                httpStatusCode: $message->getHttpStatusCode(),
                reason: $message->getReason()
            );

            $this->documentManager->persist($failedPublication);
            $this->documentManager->flush();

            $this->logger->error('[FailedVideoMessageHandler] Failed publication recorded', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'failedPublicationId' => $failedPublication->getId(),
                'httpStatusCode' => $message->getHttpStatusCode(),
            ]);

            // TODO: Enviar notificación a admin (email, Slack, etc)
            // TODO: Actualizar MultimediaObject con estado "FAILED"

        } catch (\Exception $e) {
            $this->logger->error('[FailedVideoMessageHandler] Failed to record failed publication', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Si falla el registro del fallo, relanzar para reintentar
            throw $e;
        }
    }
}
