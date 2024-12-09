<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\CoreBundle\Services\i18nService;
use Pumukit\SchemaBundle\Document\MediaType\External;
use Pumukit\SchemaBundle\Document\MediaType\MediaInterface;
use Pumukit\SchemaBundle\Document\MediaType\Metadata\Generic;
use Pumukit\SchemaBundle\Document\MediaType\Storage;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\ValueObject\i18nText;
use Pumukit\SchemaBundle\Document\ValueObject\StorageUrl;
use Pumukit\SchemaBundle\Document\ValueObject\Tags;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportYouTubeExternalPlayerCommand extends Command
{
    private $documentManager;
    private i18nService $i18nService;

    public function __construct(DocumentManager $documentManager, i18nService $i18nService)
    {
        $this->documentManager = $documentManager;
        $this->i18nService = $i18nService;
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

            if (0 !== count($multimediaObject->external())) {
                continue;
            }

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
        $externalLink = 'https://www.youtube.com/embed/'.$multimediaObject->getProperty('youtube_import_id');
        $externalMedia = $this->createExternalMedia($externalLink);
        $multimediaObject->addExternal($externalMedia);
        $multimediaObject->setExternalType();
    }

    private function createExternalMedia(string $externalLink): MediaInterface
    {
        $originalName = '';
        $description = i18nText::create($this->i18nService->generateI18nText(''));
        $language = '';
        $tags = Tags::create(['display']);
        $url = StorageUrl::create($externalLink);
        $storage = Storage::external($url);
        $metadata = Generic::create('');
        $external = External::create($originalName, $description, $language, $tags, false, false, 0, $storage, $metadata);

        $this->documentManager->persist($external);

        return $external;
    }
}
