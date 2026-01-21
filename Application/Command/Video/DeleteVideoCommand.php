<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Command\Video;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Delete\DeleteVideoMessage;
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
    name: 'youtube:video:delete',
    description: 'Delete a video from YouTube'
)]
class DeleteVideoCommand extends Command
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
            ->addOption('mm-id', null, InputOption::VALUE_REQUIRED, 'MultimediaObject ID')
            ->addOption('youtube-id', null, InputOption::VALUE_REQUIRED, 'YouTube video ID')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Execute asynchronously via messenger')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command deletes a video from YouTube.

You must provide either --mm-id or --youtube-id:

  <info>php %command.full_name% test-account --mm-id=507f1f77bcf86cd799439011</info>
  <info>php %command.full_name% test-account --youtube-id=dQw4w9WgXcQ</info>

To execute asynchronously:
  <info>php %command.full_name% test-account --mm-id=507f1f77bcf86cd799439011 --async</info>

This will:
  - Delete the video from YouTube
  - Remove it from all playlists
  - Update the Publication status to REMOVED
  - Clean up MultimediaObject properties

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $accountName = $input->getArgument('account');
        $mmId = $input->getOption('mm-id');
        $youtubeId = $input->getOption('youtube-id');
        $async = $input->getOption('async');

        // Validación: debe proporcionar al menos uno
        if (!$mmId && !$youtubeId) {
            $io->error('You must provide either --mm-id or --youtube-id');
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

            // Si se proporciona youtube-id, necesitamos encontrar el MM Object
            if ($youtubeId && !$mmId) {
                $mmObject = $this->findMultimediaObjectByYoutubeId($youtubeId, $account->getId());
                
                if (!$mmObject) {
                    $io->warning("MultimediaObject not found for YouTube video ID '{$youtubeId}'");
                    $io->note('The video will be deleted from YouTube but local cleanup may be incomplete');
                    
                    // Crear un MM Object temporal solo con el ID necesario
                    $mmId = 'temp_' . $youtubeId;
                } else {
                    $mmId = $mmObject->getId();
                }
            }

            // Si se proporciona mm-id, validar que existe
            if ($mmId && !str_starts_with($mmId, 'temp_')) {
                $mmObject = $this->documentManager->getRepository(MultimediaObject::class)->find($mmId);
                
                if (!$mmObject) {
                    $io->error("MultimediaObject with ID '{$mmId}' not found");
                    return Command::FAILURE;
                }

                // Si no se proporcionó youtube-id, intentar obtenerlo
                if (!$youtubeId) {
                    $youtubeId = $mmObject->getProperty('youtube_video_id_' . $account->getId());
                    
                    if (!$youtubeId) {
                        $io->error("YouTube video ID not found for this MultimediaObject and account");
                        return Command::FAILURE;
                    }
                }

                $io->section('Video Information');
                $io->table(
                    ['Property', 'Value'],
                    [
                        ['Title', $mmObject->getTitle()],
                        ['MM ID', $mmObject->getId()],
                        ['YouTube ID', $youtubeId],
                        ['Account', $account->getAccountName()],
                    ]
                );
            } else {
                $io->section('Video Information');
                $io->table(
                    ['Property', 'Value'],
                    [
                        ['YouTube ID', $youtubeId],
                        ['Account', $account->getAccountName()],
                    ]
                );
            }

            // Confirmar eliminación
            if (!$io->confirm('Are you sure you want to delete this video from YouTube?', false)) {
                $io->info('Operation cancelled');
                return Command::SUCCESS;
            }

            // Crear mensaje
            $message = new DeleteVideoMessage(
                $mmId,
                $account->getId(),
                $youtubeId
            );

            if ($async) {
                // Despachar mensaje de forma asíncrona
                $this->messageBus->dispatch($message);
                
                $io->success([
                    'Video deletion queued successfully!',
                    'The video will be deleted asynchronously.',
                    'Check logs for progress.'
                ]);
            } else {
                // Ejecutar síncronamente
                $io->info('Deleting video from YouTube...');
                
                $this->messageBus->dispatch($message);
                
                $io->success([
                    'Video deleted successfully from YouTube!',
                    "YouTube ID: {$youtubeId}",
                ]);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error([
                'Error deleting video:',
                $e->getMessage(),
            ]);

            if ($output->isVerbose()) {
                $io->block($e->getTraceAsString(), 'TRACE', 'fg=red', ' ', true);
            }

            return Command::FAILURE;
        }
    }

    private function findMultimediaObjectByYoutubeId(string $youtubeId, string $accountId): ?MultimediaObject
    {
        $propertyKey = 'youtube_video_id_' . $accountId;
        
        return $this->documentManager->getRepository(MultimediaObject::class)
            ->findOneBy(['properties.' . $propertyKey => $youtubeId]);
    }
}
