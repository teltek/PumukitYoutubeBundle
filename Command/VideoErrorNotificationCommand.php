<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\NotificationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VideoErrorNotificationCommand extends Command
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
            ->setName('pumukit:youtube:video:error:notification')
            ->setDescription('Notification of all errors saved on PuMuKIT.')
            ->setHelp(
                <<<'EOT'
Notification of all errors saved on PuMuKIT.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $elements = $this->getAllYouTubeDocumentsWithErrors();
        if (empty($elements)) {
            return 0;
        }
        $result = $this->processAndGroupErrors($elements);
        $this->notificationService->notificationVideoErrorResult($result);

        return 0;
    }

    private function getAllYouTubeDocumentsWithErrors(): array
    {
        $status = [Youtube::STATUS_ERROR];

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
