<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Video;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Application\Message\Caption\UploadCaptionsMessage;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'youtube:captions:upload',
    description: 'Upload captions/subtitles to a YouTube video'
)]
class UploadCaptionsCommand extends Command
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly MessageBusInterface $messageBus
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::REQUIRED, 'YouTube account name')
            ->addArgument('mm-id', InputArgument::REQUIRED, 'MultimediaObject ID')
            ->addArgument('caption-file', InputArgument::REQUIRED, 'Path to caption file (SRT/VTT)')
            ->addArgument('language', InputArgument::REQUIRED, 'Language code (es, en, fr, etc.)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Caption track name')
            ->addOption('draft', null, InputOption::VALUE_NONE, 'Upload as draft')
            ->addOption('youtube-id', null, InputOption::VALUE_REQUIRED, 'YouTube video ID (if different from stored)')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Execute asynchronously via messenger')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command uploads captions/subtitles to a YouTube video.

Basic usage:
  <info>php %command.full_name% test-account 507f1f77bcf86cd799439011 /path/to/subtitles.srt es</info>

With custom name:
  <info>php %command.full_name% test-account 507f1f77bcf86cd799439011 /path/to/subtitles.vtt en --name="English Subtitles"</info>

Upload as draft:
  <info>php %command.full_name% test-account 507f1f77bcf86cd799439011 /path/to/subtitles.srt es --draft</info>

Supported formats: SRT, VTT, SBV
Language codes: es, en, fr, de, it, pt, etc.

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $accountName = $input->getArgument('account');
        $mmId = $input->getArgument('mm-id');
        $captionFile = $input->getArgument('caption-file');
        $language = $input->getArgument('language');
        $name = $input->getOption('name');
        $draft = $input->getOption('draft');
        $youtubeId = $input->getOption('youtube-id');
        $async = $input->getOption('async');

        try {
            // Verificar que el archivo existe
            if (!file_exists($captionFile)) {
                $io->error("Caption file not found: {$captionFile}");
                return Command::FAILURE;
            }

            // Verificar extensión del archivo
            $extension = strtolower(pathinfo($captionFile, PATHINFO_EXTENSION));
            if (!in_array($extension, ['srt', 'vtt', 'sbv'])) {
                $io->error("Unsupported caption format: {$extension}. Supported: SRT, VTT, SBV");
                return Command::FAILURE;
            }

            // Obtener cuenta de YouTube
            $account = $this->documentManager->getRepository(YoutubeAccount::class)
                ->findOneBy(['accountName' => $accountName]);

            if (!$account) {
                $io->error("YouTube account '{$accountName}' not found");
                return Command::FAILURE;
            }

            if ($account->isPaused()) {
                $io->warning("Account '{$accountName}' is paused");
                
                if (!$io->confirm('Do you want to continue anyway?', false)) {
                    return Command::FAILURE;
                }
            }

            // Obtener MultimediaObject
            $mmObject = $this->documentManager->getRepository(MultimediaObject::class)->find($mmId);
            
            if (!$mmObject) {
                $io->error("MultimediaObject with ID '{$mmId}' not found");
                return Command::FAILURE;
            }

            // Si no se proporcionó youtube-id, obtenerlo del MM Object
            if (!$youtubeId) {
                $youtubeId = $mmObject->getProperty('youtube_video_id_' . $account->getId());
                
                if (!$youtubeId) {
                    $io->error("YouTube video ID not found for this MultimediaObject and account");
                    return Command::FAILURE;
                }
            }

            $io->section('Caption Information');
            $io->table(
                ['Property', 'Value'],
                [
                    ['Video Title', $mmObject->getTitle()],
                    ['MM ID', $mmObject->getId()],
                    ['YouTube ID', $youtubeId],
                    ['Account', $account->getAccountName()],
                    ['Caption File', basename($captionFile)],
                    ['File Size', $this->formatBytes(filesize($captionFile))],
                    ['Format', strtoupper($extension)],
                    ['Language', $language],
                    ['Name', $name ?? $language],
                    ['Draft', $draft ? 'Yes' : 'No'],
                ]
            );

            // Confirmar subida
            if (!$io->confirm('Do you want to upload these captions to YouTube?', true)) {
                $io->info('Operation cancelled');
                return Command::SUCCESS;
            }

            // Crear mensaje
            $message = new UploadCaptionsMessage(
                $mmId,
                $account->getId(),
                $youtubeId,
                $captionFile,
                $language,
                $name,
                $draft
            );

            if ($async) {
                // Despachar mensaje de forma asíncrona
                $this->messageBus->dispatch($message);
                
                $io->success([
                    'Captions upload queued successfully!',
                    'The captions will be uploaded asynchronously.',
                    'Check logs for progress.'
                ]);
            } else {
                // Ejecutar síncronamente
                $io->info('Uploading captions to YouTube...');
                
                $this->messageBus->dispatch($message);
                
                $io->success([
                    'Captions uploaded successfully to YouTube!',
                    "YouTube ID: {$youtubeId}",
                    "Language: {$language}",
                ]);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error([
                'Error uploading captions:',
                $e->getMessage(),
            ]);

            if ($output->isVerbose()) {
                $io->block($e->getTraceAsString(), 'TRACE', 'fg=red', ' ', true);
            }

            return Command::FAILURE;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return round($bytes / 1048576, 2) . ' MB';
        }
    }
}
