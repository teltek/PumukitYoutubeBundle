<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\NotificationService;
use Pumukit\YoutubeBundle\Services\VideoService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeUpdateStatusCommand extends Command
{
    protected $documentManager;
    protected $videoService;
    protected $notificationService;
    protected $okUpdates = [];
    protected $failedUpdates = [];
    protected $errors = [];
    protected $usePumukit1 = false;

    public function __construct(
        DocumentManager $documentManager,
        VideoService $videoService,
        NotificationService $notificationService
    ) {
        $this->documentManager = $documentManager;
        $this->videoService = $videoService;
        $this->notificationService = $notificationService;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pumukit:youtube:update:status')
            ->addOption('use-pmk1', null, InputOption::VALUE_NONE, 'Use multimedia objects from PuMuKIT1')
            ->setDescription('Update local YouTube status of the video')
            ->setHelp(
                <<<'EOT'
Update the local YouTube status stored in PuMuKIT YouTube collection, getting the info from the YouTube service using the API. If enabled it sends an email with a summary.

The statuses removed, notified error and duplicated are not updated.

The statuses uploading and processing are not updated, use command pending status instead.

PERFORMANCE NOTE: This command has a bad performance because use all the multimedia objects uploaded in Youtube Service. (See youtube:update:pendingstatus)

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

        $this->updateVideoStatusInYoutube($youtubeDocuments, $output);

        $this->notificationService->notificationOfUpdatedStatusVideoResults(
            $this->okUpdates,
            $this->failedUpdates,
            $this->errors
        );

        return 0;
    }

    protected function updateVideoStatusInYoutube($youtubeDocuments, OutputInterface $output)
    {
        foreach ($youtubeDocuments as $youtube) {
            if (!$youtube->getYoutubeId()) {
                $errorLog = __CLASS__.' ['.__FUNCTION__.'] The object Youtube with id: '.$youtube->getId().' does not have a Youtube ID variable set.';
                $youtube->setStatus(Youtube::STATUS_ERROR);
                $youtube->setYoutubeError($errorLog);
                $youtube->setYoutubeErrorDate(new \DateTime('now'));
                $this->documentManager->flush();

                continue;
            }
            $multimediaObject = $this->findMultimediaObjectByYoutubeDocument($youtube);
            if (!$multimediaObject instanceof MultimediaObject) {
                $errorLog = sprintf("No multimedia object for YouTube document %s\n", $youtube->getId());
                $youtube->setStatus(Youtube::STATUS_ERROR);
                $youtube->setYoutubeError($errorLog);
                $youtube->setYoutubeErrorDate(new \DateTime('now'));
                $this->documentManager->flush();

                continue;
            }

            try {
                $infoLog = sprintf(
                    '%s [%s] Started updating internal YouTube status video of MultimediaObject with id %s',
                    __CLASS__,
                    __FUNCTION__,
                    $multimediaObject->getId()
                );
                $output->writeln($infoLog);

                $result = $this->videoService->updateVideoStatus($youtube, $multimediaObject);
                if (!$result) {
                    $this->failedUpdates[] = $multimediaObject;
                } else {
                    $this->okUpdates[] = $multimediaObject;
                }
            } catch (\Exception $e) {
                $errorLog = sprintf(
                    '%s [%s] The update status of the video from the Multimedia Object with id %s failed: %s',
                    __CLASS__,
                    __FUNCTION__,
                    $multimediaObject->getId(),
                    $e->getMessage()
                );
                $output->writeln($errorLog);
                $this->failedUpdates[] = $multimediaObject;
                $this->errors[] = $e->getMessage();
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
