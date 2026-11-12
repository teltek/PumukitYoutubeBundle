<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Collection;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes empty error fields and empty status errors from the Youtube documents, and lists
 * the documents in error status without a youtubeId, which need a manual review.
 */
class CleanupErrorsCommand extends Command
{
    private const ERROR_FIELDS = ['error', 'metadataUpdateError', 'playlistUpdateError', 'captionUpdateError'];

    private const REPORT_SAMPLE_LIMIT = 50;

    private DocumentManager $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        parent::__construct();
        $this->documentManager = $documentManager;
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:errors:cleanup')
            ->setDescription('Remove empty error fields and empty status errors from the YouTube documents.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply changes. Without this flag the command runs in dry-run mode.')
            ->addOption(
                'field',
                null,
                InputOption::VALUE_REQUIRED,
                'Restrict cleanup to a single error field ('.implode('|', self::ERROR_FIELDS).'|all).',
                'all'
            )
            ->setHelp(
                <<<'EOT'
Cleans two kinds of inconsistent data in the YouTube documents that earlier versions
of the bundle could leave behind:

  1. Empty error fields: error, metadataUpdateError, playlistUpdateError or
     captionUpdateError stored as null. For "error", only in the documents whose
     status is not error. The field is removed.

  2. Empty status errors: documents in error status, with a YouTube id, whose error
     is "pumukit.updateStatusError" without a message. The error is removed and the
     status is kept, so the next run of pumukit:youtube:video:update:status checks
     the video on YouTube and sets its real status.

It also lists the documents in error status without a YouTube id. They cannot be
checked on YouTube and are never changed: review them by hand.

Run it once after upgrading from an earlier version of the bundle, or when the
statistics show errors without a message. Without --apply it only shows what it
would change. Use --field to clean a single error field.
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $field = (string) $input->getOption('field');

        $fields = $this->resolveFields($field);
        if (null === $fields) {
            $io->error(sprintf('Unknown --field value "%s". Use one of: %s, all.', $field, implode(', ', self::ERROR_FIELDS)));

            return 1;
        }

        $io->title('YouTube error fields cleanup');
        $io->writeln($apply ? '<comment>Mode: APPLY (changes will be persisted)</comment>' : '<info>Mode: dry-run (no changes)</info>');
        $io->newLine();

        $collection = $this->documentManager->getDocumentCollection(Youtube::class);

        $rows = [];
        $totalResidue = 0;
        $totalFalseErrors = 0;

        foreach ($fields as $errorField) {
            $residueFilter = $this->residueFilter($errorField);
            $residueCount = $collection->countDocuments($residueFilter);
            $totalResidue += $residueCount;

            $falseErrorCount = 0;
            if ('error' === $errorField) {
                $falseErrorFilter = $this->falseUpdateStatusErrorFilter();
                $falseErrorCount = $collection->countDocuments($falseErrorFilter);
                $totalFalseErrors += $falseErrorCount;
            }

            $rows[] = [$errorField, $residueCount, 'error' === $errorField ? $falseErrorCount : '—'];
        }

        $io->section('Documents matched');
        $io->table(
            ['Field', 'Residue (field: null, non-error status)', 'False STATUS_ERROR (only "error")'],
            $rows
        );

        $unreconcilable = $this->fetchUnreconcilable($collection);
        $io->section(sprintf('Unreconcilable STATUS_ERROR documents without youtubeId (%d)', count($unreconcilable)));
        if ($unreconcilable) {
            $sample = array_slice($unreconcilable, 0, self::REPORT_SAMPLE_LIMIT);
            $io->table(
                ['_id', 'multimediaObjectId', 'youtubeAccount', 'uploadDate', 'error.id', 'error.message'],
                array_map(static fn (array $doc) => [
                    (string) ($doc['_id'] ?? ''),
                    (string) ($doc['multimediaObjectId'] ?? ''),
                    (string) ($doc['youtubeAccount'] ?? ''),
                    isset($doc['uploadDate']) ? $doc['uploadDate']->toDateTime()->format('Y-m-d H:i:s') : '',
                    (string) ($doc['error']['id'] ?? ''),
                    (string) ($doc['error']['message'] ?? ''),
                ], $sample)
            );
            if (count($unreconcilable) > self::REPORT_SAMPLE_LIMIT) {
                $io->note(sprintf('Only showing first %d of %d rows. These documents need manual review.', self::REPORT_SAMPLE_LIMIT, count($unreconcilable)));
            } else {
                $io->note('These documents need manual review — they cannot be reconciled against the YouTube API without a youtubeId.');
            }
        } else {
            $io->success('No unreconcilable STATUS_ERROR documents found.');
        }

        if (!$apply) {
            $io->newLine();
            $io->writeln(sprintf(
                '<info>Dry-run summary: %d residue documents and %d false STATUS_ERROR documents would be cleaned. Re-run with --apply to persist.</info>',
                $totalResidue,
                $totalFalseErrors
            ));

            return 0;
        }

        $io->section('Applying cleanup');

        $residueUpdates = 0;
        $falseErrorUpdates = 0;

        foreach ($fields as $errorField) {
            $result = $collection->updateMany(
                $this->residueFilter($errorField),
                ['$unset' => [$errorField => '']]
            );
            $modified = $result->getModifiedCount();
            $residueUpdates += $modified;
            $io->writeln(sprintf(' - Unset %s (residue): %d documents', $errorField, $modified));

            if ('error' === $errorField) {
                $result = $collection->updateMany(
                    $this->falseUpdateStatusErrorFilter(),
                    ['$unset' => ['error' => '']]
                );
                $modified = $result->getModifiedCount();
                $falseErrorUpdates += $modified;
                $io->writeln(sprintf(' - Unset error (false STATUS_ERROR): %d documents', $modified));
            }
        }

        $io->newLine();
        $io->success(sprintf(
            'Cleanup applied. %d residue fields and %d false STATUS_ERROR errors cleared. Next `pumukit:youtube:video:update:status` run will reconcile the STATUS_ERROR documents against YouTube.',
            $residueUpdates,
            $falseErrorUpdates
        ));

        return 0;
    }

    private function resolveFields(string $field): ?array
    {
        if ('all' === $field) {
            return self::ERROR_FIELDS;
        }

        if (in_array($field, self::ERROR_FIELDS, true)) {
            return [$field];
        }

        return null;
    }

    private function residueFilter(string $errorField): array
    {
        $filter = [$errorField => null];
        if ('error' === $errorField) {
            $filter['status'] = ['$ne' => Youtube::STATUS_ERROR];
        }

        return $filter;
    }

    private function falseUpdateStatusErrorFilter(): array
    {
        return [
            'status' => Youtube::STATUS_ERROR,
            'youtubeId' => ['$exists' => true, '$nin' => [null, '']],
            'error.id' => 'pumukit.updateStatusError',
            '$or' => [
                ['error.message' => ''],
                ['error.message' => null],
                ['error.message' => ['$exists' => false]],
            ],
        ];
    }

    private function fetchUnreconcilable(Collection $collection): array
    {
        $cursor = $collection->find(
            [
                'status' => Youtube::STATUS_ERROR,
                '$or' => [
                    ['youtubeId' => ['$exists' => false]],
                    ['youtubeId' => null],
                    ['youtubeId' => ''],
                ],
            ],
            [
                'projection' => [
                    '_id' => 1,
                    'multimediaObjectId' => 1,
                    'youtubeAccount' => 1,
                    'uploadDate' => 1,
                    'error' => 1,
                ],
                'sort' => ['uploadDate' => -1],
            ]
        );

        return $cursor->toArray();
    }
}
