<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Video;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Application\Message\Video\UpdateVideoMessage;
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
    name: 'youtube:video:update',
    description: 'Update video metadata on YouTube'
)]
class UpdateVideoCommand extends Command
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
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'New title')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'New description')
            ->addOption('privacy', null, InputOption::VALUE_REQUIRED, 'Privacy status (public, unlisted, private)')
            ->addOption('tags', null, InputOption::VALUE_REQUIRED, 'Tags (comma-separated)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'YouTube category ID')
            ->addOption('sync-from-mm', null, InputOption::VALUE_NONE, 'Sync metadata from MultimediaObject')
            ->addOption('youtube-id', null, InputOption::VALUE_REQUIRED, 'YouTube video ID (if different from stored)')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Execute asynchronously via messenger')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command updates video metadata on YouTube.

Update specific fields:
  <info>php %command.full_name% test-account 507f1f77bcf86cd799439011 --title="New Title"</info>
  <info>php %command.full_name% test-account 507f1f77bcf86cd799439011 --privacy=public</info>
  <info>php %command.full_name% test-account 507f1f77bcf86cd799439011 --tags="tag1,tag2,tag3"</info>

Sync all metadata from MultimediaObject:
  <info>php %command.full_name% test-account 507f1f77bcf86cd799439011 --sync-from-mm</info>

Update multiple fields:
  <info>php %command.full_name% test-account 507f1f77bcf86cd799439011 --title="New Title" --description="New Description" --privacy=unlisted</info>

Privacy values: public, unlisted, private

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $accountName = $input->getArgument('account');
        $mmId = $input->getArgument('mm-id');
        $title = $input->getOption('title');
        $description = $input->getOption('description');
        $privacy = $input->getOption('privacy');
        $tags = $input->getOption('tags');
        $category = $input->getOption('category');
        $syncFromMM = $input->getOption('sync-from-mm');
        $youtubeId = $input->getOption('youtube-id');
        $async = $input->getOption('async');

        // Validar que se proporcione al menos una opción
        if (!$title && !$description && !$privacy && !$tags && !$category && !$syncFromMM) {
            $io->error('You must provide at least one option to update (--title, --description, --privacy, --tags, --category) or use --sync-from-mm');
            return Command::FAILURE;
        }

        // Validar privacy
        if ($privacy && !in_array($privacy, ['public', 'unlisted', 'private'])) {
            $io->error('Privacy must be one of: public, unlisted, private');
            return Command::FAILURE;
        }

        try {
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

            // Preparar metadata
            $metadata = [];
            
            if ($syncFromMM) {
                $metadata = [
                    'title' => $mmObject->getTitle(),
                    'description' => $mmObject->getDescription(),
                    'tags' => $mmObject->getTags(),
                ];
                
                $io->info('Syncing metadata from MultimediaObject');
            } else {
                if ($title) {
                    $metadata['title'] = $title;
                }
                if ($description) {
                    $metadata['description'] = $description;
                }
                if ($privacy) {
                    $metadata['privacy'] = $privacy;
                }
                if ($tags) {
                    $metadata['tags'] = array_map('trim', explode(',', $tags));
                }
                if ($category) {
                    $metadata['category'] = $category;
                }
            }

            $io->section('Video Information');
            $io->table(
                ['Property', 'Current Value', 'New Value'],
                [
                    ['Title', $mmObject->getTitle(), $metadata['title'] ?? 'No change'],
                    ['Description', substr($mmObject->getDescription() ?? '', 0, 50) . '...', isset($metadata['description']) ? substr($metadata['description'], 0, 50) . '...' : 'No change'],
                    ['Privacy', '-', $metadata['privacy'] ?? 'No change'],
                    ['Tags', implode(', ', $mmObject->getTags() ?? []), isset($metadata['tags']) ? implode(', ', $metadata['tags']) : 'No change'],
                    ['MM ID', $mmObject->getId(), '-'],
                    ['YouTube ID', $youtubeId, '-'],
                    ['Account', $account->getAccountName(), '-'],
                ]
            );

            // Confirmar actualización
            if (!$io->confirm('Do you want to update this video on YouTube?', true)) {
                $io->info('Operation cancelled');
                return Command::SUCCESS;
            }

            // Crear mensaje
            $message = new UpdateVideoMessage(
                $mmId,
                $account->getId(),
                $youtubeId,
                $syncFromMM,
                $metadata
            );

            if ($async) {
                // Despachar mensaje de forma asíncrona
                $this->messageBus->dispatch($message);
                
                $io->success([
                    'Video update queued successfully!',
                    'The video will be updated asynchronously.',
                    'Check logs for progress.'
                ]);
            } else {
                // Ejecutar síncronamente
                $io->info('Updating video on YouTube...');
                
                $this->messageBus->dispatch($message);
                
                $io->success([
                    'Video updated successfully on YouTube!',
                    "YouTube ID: {$youtubeId}",
                ]);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error([
                'Error updating video:',
                $e->getMessage(),
            ]);

            if ($output->isVerbose()) {
                $io->block($e->getTraceAsString(), 'TRACE', 'fg=red', ' ', true);
            }

            return Command::FAILURE;
        }
    }
}
