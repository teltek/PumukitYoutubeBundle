<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\VideoListService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VideoUpdateStatusCommand extends Command
{
    protected $documentManager;
    protected $videoListService;

    protected $logger;
    protected $usePumukit1 = false;

    public function __construct(
        DocumentManager $documentManager,
        VideoListService $videoListService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->videoListService = $videoListService;
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:video:update:status')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Update local YouTube status of the video')
            ->setHelp(
                <<<'EOT'
Update the local YouTube status stored in PuMuKIT YouTube collection, getting the info from the YouTube service using the API. If enabled it sends an email with a summary.

The statuses removed, notified error and duplicated are not updated.

The statuses uploading and processing are not updated, use command pending status instead.

PERFORMANCE NOTE: This command has a bad performance because use all the multimedia objects uploaded in Youtube Service. (See youtube:video:update:pendingstatus)

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->usePumukit1 = $input->getOption('use-pmk1');

        $statusArray = [
            Youtube::STATUS_REMOVED,
            Youtube::STATUS_DUPLICATED,
            Youtube::STATUS_UPLOADING,
            Youtube::STATUS_PROCESSING,
        ];
        $youtubeDocuments = $this->documentManager->getRepository(Youtube::class)->getWithoutAnyStatus($statusArray);

        $infoLog = '[YouTube] Updating status for '.(is_countable($youtubeDocuments) ? count($youtubeDocuments) : 0).' videos.';
        $this->logger->info($infoLog);
        $this->updateVideoStatusInYoutube($youtubeDocuments, $output);

        return 0;
    }

    protected function updateVideoStatusInYoutube($youtubeDocuments, OutputInterface $output): void
    {
        foreach ($youtubeDocuments as $youtube) {
            if (!$youtube->getYoutubeId()) {
                $errorLog = sprintf('YouTube document %s does not have a Youtube ID variable set.', $youtube->getId());
                $youtube->setStatus(Youtube::STATUS_ERROR);
                $error = Error::create(
                    'pumukit.youtubeIdNotFound',
                    $errorLog,
                    new \DateTime(),
                    ''
                );
                $youtube->setError($error);
                $this->documentManager->flush();

                $this->logger->error($errorLog);

                continue;
            }
            $multimediaObject = $this->findMultimediaObjectByYoutubeDocument($youtube);
            if (!$multimediaObject instanceof MultimediaObject) {
                $errorLog = sprintf("No multimedia object for YouTube document %s\n", $youtube->getId());
                $youtube->setStatus(Youtube::STATUS_ERROR);
                $error = Error::create(
                    'pumukit.videoNotFound',
                    $errorLog,
                    new \DateTime(),
                    ''
                );
                $youtube->setError($error);
                $this->documentManager->flush();

                $this->logger->error($errorLog);

                continue;
            }

            try {
                $result = $this->videoListService->updateVideoStatus($youtube, $multimediaObject);
            } catch (\Exception $e) {
                $errorLog = sprintf('[YouTube] Update status of the video %s failed: %s', $multimediaObject->getId(), $e->getMessage());
                $output->writeln($errorLog);
                $this->logger->error($errorLog);
            }
        }
    }

    protected function findByYoutubeIdAndPumukit1Id(Youtube $youtube, $pumukit1Id = false)
    {
        $qb = $this->documentManager->getRepository(MultimediaObject::class)
            ->createQueryBuilder()
            ->field('_id')->equals(new ObjectId($youtube->getMultimediaObjectId()))
            ->field('properties.origin')
            ->notEqual('youtube')
        ;

        if (!$this->usePumukit1) {
            $qb->field('properties.pumukit1id')
                ->exists($pumukit1Id)
            ;
        }

        return $qb
            ->getQuery()
            ->getSingleResult()
        ;
    }

    protected function findByYoutubeId(Youtube $youtube)
    {
        return $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('_id')->equals(new ObjectId($youtube->getMultimediaObjectId()))
            ->getQuery()
            ->getSingleResult()
        ;
    }

    private function findMultimediaObjectByYoutubeDocument(Youtube $youtube)
    {
        $multimediaObject = $this->findByYoutubeIdAndPumukit1Id($youtube, false);
        if (!$multimediaObject instanceof MultimediaObject) {
            $multimediaObject = $this->findByYoutubeId($youtube);
            if (!$multimediaObject instanceof MultimediaObject) {
                return null;
            }
        }

        return $multimediaObject;
    }
}
