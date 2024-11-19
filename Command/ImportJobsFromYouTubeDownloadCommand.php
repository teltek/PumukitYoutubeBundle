<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\CoreBundle\Utils\FinderUtils;
use Pumukit\EncoderBundle\Services\DTO\JobOptions;
use Pumukit\EncoderBundle\Services\JobCreator;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\ValueObject\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportJobsFromYouTubeDownloadCommand extends Command
{
    public const DEFAULT_PROFILE_ENCODER = 'broadcastable_master';

    private DocumentManager $documentManager;
    private $jobCreator;
    private string $tempDir;
    private string $channelId;
    private $youtubeErrors = [];

    public function __construct(
        DocumentManager $documentManager,
        JobCreator $jobCreator,
        string $tempDir
    ) {
        $this->documentManager = $documentManager;
        $this->jobCreator = $jobCreator;
        $this->tempDir = $tempDir;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:import:add:jobs')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Channel ID')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'limit')
            ->setDescription('Import all videos from Youtube channel')
            ->setHelp(
                <<<'EOT'

Generate jobs for all videos downloaded from Youtube channel

Limit is optional to test the command.

Usage: php bin/console pumukit:youtube:import:add:jobs --account={ACCOUNT} --channel={CHANNEL_ID} --limit={LIMIT}

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->channelId = $channel = $input->getOption('channel');
        $limit = (int) $input->getOption('limit') ?? null;

        $this->ensureYouTubeAccountExists($input);

        $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->findBy([
            'status' => ['$in' => [MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_HIDDEN]],
            'properties.youtube_import_status' => ['$exists' => true],
            'properties.youtube_import_channel' => $channel,
        ], [], $limit);

        $progressBar = new ProgressBar($output, count($multimediaObjects));
        $progressBar->start();

        $count = 0;
        foreach ($multimediaObjects as $multimediaObject) {
            $progressBar->advance();
            if (null !== $input->getOption('limit') && $count >= $input->getOption('limit')) {
                break;
            }
            ++$count;
            $this->addJob($multimediaObject, $multimediaObject->getProperty('youtube_import_id'));
        }

        $progressBar->finish();
        $output->writeln(' ');

        foreach ($this->youtubeErrors as $error) {
            $output->writeln($error);
        }

        return 0;
    }

    private function addJob(MultimediaObject $multimediaObject, string $youtubeId): MultimediaObject
    {
        $path = $this->tempDir.'/'.$this->channelId.'/';
        $trackUrl = FinderUtils::findFilePathname($path, $youtubeId);

        if (!$trackUrl) {
            $this->youtubeErrors[] = 'Cannot find file: '.$path.$youtubeId;

            return $multimediaObject;
        }

        $jobOptions = new JobOptions(self::DEFAULT_PROFILE_ENCODER, 0, null, [], []);
        $path = Path::create($trackUrl);

        return $this->jobCreator->fromPath($multimediaObject, $path, $jobOptions);
    }

    private function ensureYouTubeAccountExists(InputInterface $input): void
    {
        $youtubeAccount = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $input->getOption('account'),
        ]);

        if (!$youtubeAccount) {
            throw new \Exception('Account not found');
        }
    }
}
