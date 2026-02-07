<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Video;

use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Application\Message\Video\UpdatePublicationMessage;
use Pumukit\YoutubeBundle\Application\UseCase\UpdatePublicationUseCase;

final class UpdatePublicationMessageHandler
{
    public function __construct(
        private readonly UpdatePublicationUseCase $updatePublicationUseCase,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UpdatePublicationMessage $message): void
    {
        $this->logger->info('Handling update publication message', [
            'publicationId' => $message->getPublicationId(),
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        try {
            $this->updatePublicationUseCase->execute(
                $message->getPublicationId(),
                $message->getMultimediaObjectId(),
                $message->getYoutubeAccountId(),
                $message->getPlaylists()
            );

            $this->logger->info('Update publication message handled successfully');
        } catch (\Exception $e) {
            $this->logger->error('Error handling update publication message', [
                'error' => $e->getMessage(),
                'publicationId' => $message->getPublicationId(),
            ]);

            throw $e;
        }
    }
}
