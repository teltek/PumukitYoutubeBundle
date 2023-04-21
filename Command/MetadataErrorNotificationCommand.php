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
        $elements = $this->getAllYouTubeDocumentsWithErrors();
        $result = $this->processAndGroupErrors($elements);
        $this->notificationService->notificationVideoErrorResult($result);

        return 0;
    }

    private function getAllYouTubeDocumentsWithErrors(): array
    {
        $status = [Youtube::STATUS_PUBLISHED];

        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => ['$in' => $status],
            'error' => ['$exists' => true],
        ]);
    }

    private function processAndGroupErrors(array $elements): array
    {
        $groupResult = [];
        foreach ($elements as $element) {
            $groupResult[$element->getError()->id()][] = $element;
        }

        return $groupResult;
    }
}
