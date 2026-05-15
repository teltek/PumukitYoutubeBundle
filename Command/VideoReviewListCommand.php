<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VideoReviewListCommand extends Command
{
    private $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:video:review:list')
            ->setDescription('List Youtube documents in STATUS_TO_REVIEW')
            ->setHelp(
                <<<'EOT'
List all Youtube documents currently in STATUS_TO_REVIEW so an operator can
reconcile them via pumukit:youtube:video:review:assign.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $youtubeDocuments = $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => Youtube::STATUS_TO_REVIEW,
        ]);

        if (empty($youtubeDocuments)) {
            $output->writeln('<info>No Youtube documents in STATUS_TO_REVIEW.</info>');

            return 0;
        }

        $table = new Table($output);
        $table->setHeaders(['Youtube doc ID', 'MultimediaObject ID', 'MMObj title', 'Account', 'Error reason', 'Updated']);

        foreach ($youtubeDocuments as $youtube) {
            $mmObj = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                '_id' => new ObjectId($youtube->getMultimediaObjectId()),
            ]);

            $title = $mmObj instanceof MultimediaObject ? (string) $mmObj->getTitle() : '<missing>';
            $error = $youtube->getError();

            $table->addRow([
                $youtube->getId(),
                $youtube->getMultimediaObjectId(),
                mb_strimwidth($title, 0, 50, '…'),
                $youtube->getYoutubeAccount() ?? '',
                $error ? $error->id() : '',
                $youtube->getSyncMetadataDate()->format('Y-m-d H:i'),
            ]);
        }

        $table->render();
        $output->writeln(sprintf('<info>%d document(s) pending review.</info>', count($youtubeDocuments)));

        return 0;
    }
}
