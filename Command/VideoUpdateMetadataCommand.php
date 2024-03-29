<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\VideoUpdateService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VideoUpdateMetadataCommand extends Command
{
    private $documentManager;
    private $videoUpdateService;
    private $logger;
    private $usePumukit1 = false;

    public function __construct(
        DocumentManager $documentManager,
        VideoUpdateService $videoUpdateService,
        LoggerInterface $logger,
    ) {
        $this->documentManager = $documentManager;
        $this->videoUpdateService = $videoUpdateService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:video:update:metadata')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Update Youtube metadata from Multimedia Objects')
            ->setHelp(
                <<<'EOT'
Sync YouTube Service metadata with local metadata(title, description...). Meaning, update the metadata of the YouTube service getting the info from the local multimedia objects.

PERFORMANCE NOTE: This command has a good performance because only use the multimedia objects updated from the last synchronization.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->usePumukit1 = $input->getOption('use-pmk1');

        $multimediaObjects = $this->getMultimediaObjectsInYoutubeToUpdate();

        $infoLog = '[YouTube] Updating metadata for '.count($multimediaObjects).' videos on YouTube';
        $output->writeln($infoLog);
        $this->updateVideosInYoutube($multimediaObjects, $output);

        return 0;
    }

    private function updateVideosInYoutube($multimediaObjects, OutputInterface $output): void
    {
        foreach ($multimediaObjects as $multimediaObject) {
            try {
                $result = $this->videoUpdateService->updateVideoOnYoutube($multimediaObject);
            } catch (\Exception $e) {
                $errorLog = sprintf('[YouTube] Update metadata video for video %s failed: %s', $multimediaObject->getId(), $e->getMessage());
                $this->logger->error($errorLog);
                $output->writeln($errorLog);
            }
        }
    }

    private function getMultimediaObjectsInYoutubeToUpdate(): array
    {
        $mongoObjectIds = $this->documentManager->getRepository(Youtube::class)->getDistinctIdsNotMetadataUpdated();
        $youtubeIds = [];
        foreach ($mongoObjectIds as $mongoObjectId) {
            $youtubeIds[] = $mongoObjectId->__toString();
        }

        $criteria = [
            'properties.origin' => ['$ne' => 'youtube'],
            'properties.youtube' => ['$in' => $youtubeIds],
        ];

        if (!$this->usePumukit1) {
            $criteria['properties.pumukit1id'] = ['$exists' => false];
        }

        return $this->documentManager->getRepository(MultimediaObject::class)->findBy($criteria);
    }
}
