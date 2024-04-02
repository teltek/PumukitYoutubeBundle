<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportYouTubeExternalPlayerCommand extends Command
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
            ->setName('pumukit:youtube:import:externalplayer')
            ->setDescription('Add external player to multimedia objects to show Youtube videos')
            ->setHelp(
                <<<'EOT'

Add external player to multimedia objects to show Youtube videos

Usage: php bin/console pumukit:youtube:import:externalplayer

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $multimediaObjects = $this->obtainMultimediaObjects();

        $progressBar = new ProgressBar($output, count($multimediaObjects));
        $progressBar->start();

        $count = 0;
        foreach ($multimediaObjects as $multimediaObject) {
            $progressBar->advance();
            ++$count;

            $this->updateMultimediaObject($multimediaObject);

            if (0 == $count % 50) {
                $this->documentManager->flush();
            }
        }

        $progressBar->finish();

        $this->documentManager->flush();
        $this->documentManager->clear();

        return 0;
    }

    private function obtainMultimediaObjects()
    {
        return $this->documentManager->getRepository(MultimediaObject::class)->findBy([
            'properties.youtube_import_id' => ['$exists' => true],
            'properties.externalplayer' => ['$exists' => false],
        ]);
    }

    private function updateMultimediaObject(MultimediaObject $multimediaObject): void
    {
        $externalPlayer = 'https://www.youtube.com/embed/'.$multimediaObject->getProperty('youtube_import_id');
        $multimediaObject->setProperty('externalplayer', $externalPlayer);
        $multimediaObject->setType(MultimediaObject::TYPE_EXTERNAL);
    }
}
