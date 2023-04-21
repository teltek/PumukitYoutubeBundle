<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\NotificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MetadataErrorNotificationCommand extends Command
{
    private $documentManager;
    private $notificationService;

    public function __construct(DocumentManager $documentManager, NotificationService $notificationService)
    {
        $this->documentManager = $documentManager;
        $this->notificationService = $notificationService;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:metadata:error:notification')
            ->setDescription('Notification of all errors of update metadata saved on PuMuKIT.')
            ->setHelp(
                <<<'EOT'
Notification of all errors of update metadata saved on PuMuKIT.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $metadataUpdateErrors = $this->getMetadataUpdateError();
        $playlistsUpdateErrors = $this->getPlaylistUpdateError();
        $captionsUpdateErrors = $this->getCaptionUpdateError();

        $elements = array_merge($metadataUpdateErrors, $playlistsUpdateErrors, $captionsUpdateErrors);

        $result = $this->processAndGroupErrors($elements);
        $this->notificationService->notificationVideoErrorResult($result);

        return 0;
    }

    private function getMetadataUpdateError(): array
    {
        $status = [Youtube::STATUS_PUBLISHED];

        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => ['$in' => $status],
            'metadataUpdateError' => ['$exists' => true],
        ]);
    }

    private function getPlaylistUpdateError(): array
    {
        $status = [Youtube::STATUS_PUBLISHED];

        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => ['$in' => $status],
            'playlistUpdateError' => ['$exists' => true],
        ]);
    }

    private function getCaptionUpdateError(): array
    {
        $status = [Youtube::STATUS_PUBLISHED];

        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => ['$in' => $status],
            'captionUpdateError' => ['$exists' => true],
        ]);
    }

    private function processAndGroupErrors(array $elements): array
    {
        $groupResult = [];
        foreach ($elements as $element) {
            if ($element->getMetadataUpdateError()) {
                $groupResult[$element->getMetadataUpdateError()->id()][] = $element;
            }

            if ($element->getPlaylistUpdateError()) {
                $groupResult[$element->getPlaylistUpdateError()->id()][] = $element;
            }

            if ($element->getCaptionUpdateError()) {
                $groupResult[$element->getCaptionUpdateError()->id()][] = $element;
            }
        }

        return $groupResult;
    }
}
