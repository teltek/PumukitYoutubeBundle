<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class TagsReconcileCommand extends Command
{
    private const BATCH_SIZE = 50;

    private const STATUS_LABELS = [
        Youtube::STATUS_DEFAULT => 'Default',
        Youtube::STATUS_UPLOADING => 'Uploading',
        Youtube::STATUS_PROCESSING => 'Processing',
        Youtube::STATUS_PUBLISHED => 'Published',
        Youtube::STATUS_ERROR => 'Error',
        Youtube::STATUS_DUPLICATED => 'Duplicated',
        Youtube::STATUS_REMOVED => 'Removed',
        Youtube::STATUS_TO_DELETE => 'To delete',
        Youtube::STATUS_TO_REVIEW => 'To review',
    ];

    private const STRIP_STATUSES = [Youtube::STATUS_REMOVED, Youtube::STATUS_TO_DELETE];

    private DocumentManager $documentManager;
    private TagService $tagService;

    private Tag $youtubeRootTag;
    private Tag $puchYoutubeTag;

    public function __construct(DocumentManager $documentManager, TagService $tagService)
    {
        parent::__construct();
        $this->documentManager = $documentManager;
        $this->tagService = $tagService;
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:tags:reconcile')
            ->setDescription('Reconcile YouTube account/playlist tags between Youtube documents and MultimediaObjects')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Limit to a specific account login')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply changes (otherwise dry-run)')
            ->addOption('report-only', null, InputOption::VALUE_NONE, 'Only run the consistency report')
            ->setHelp(
                <<<'EOT'
The Youtube document is the source of truth. For each YT doc, the linked MMO's
embedded YT tags are reconciled (add missing, refresh stale, remove orphans).
REMOVED/TO_DELETE docs are stripped of all YT tags. MMOs tagged with PUCHYOUTUBE
but without a Youtube document are stripped as orphans. A final report lists
MMOs still flagged as inconsistent.

  pumukit:youtube:tags:reconcile                  Dry-run
  pumukit:youtube:tags:reconcile --force          Apply
  pumukit:youtube:tags:reconcile --account=login  Limit to one account
  pumukit:youtube:tags:reconcile --report-only    Skip sync, only inconsistency report
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filterAccount = $input->getOption('account');
        $apply = (bool) $input->getOption('force');
        $reportOnly = (bool) $input->getOption('report-only');

        $io->title(sprintf('YouTube Account Tags — %s', $this->modeLabel($apply, $reportOnly)));

        if (!$this->loadRootTags()) {
            $io->error('YOUTUBE or PUCHYOUTUBE tag missing. Run pumukit:youtube:init:tags first.');

            return Command::FAILURE;
        }

        $totals = $this->initTotals();
        $syncRows = $orphanRows = [];

        if (!$reportOnly) {
            $syncRows = $this->processYoutubeDocuments($filterAccount, $apply, $totals, $io);
            if (null === $filterAccount) {
                $orphanRows = $this->processOrphanMmos($apply, $totals, $io);
            }
        }

        $inconsistencyRows = $this->detectMmoInconsistencies($filterAccount, $io);

        $this->renderReport($io, $syncRows, $orphanRows, $inconsistencyRows, $totals, $apply, $reportOnly);

        return Command::SUCCESS;
    }

    private function modeLabel(bool $apply, bool $reportOnly): string
    {
        if ($apply) {
            return 'APPLY';
        }

        return $reportOnly ? 'report-only' : 'dry-run';
    }

    private function loadRootTags(): bool
    {
        $repo = $this->documentManager->getRepository(Tag::class);
        $root = $repo->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);
        $puch = $repo->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE]);

        if (!$root || !$puch) {
            return false;
        }

        $this->youtubeRootTag = $root;
        $this->puchYoutubeTag = $puch;

        return true;
    }

    private function initTotals(): array
    {
        return [
            'checked' => 0,
            'added' => 0,
            'removed' => 0,
            'noop' => 0,
            'mmo_missing' => 0,
            'unresolvable' => 0,
            'orphans' => 0,
        ];
    }

    private function processYoutubeDocuments(?string $filterAccount, bool $apply, array &$totals, SymfonyStyle $io): array
    {
        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder();
        if ($filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }
        $total = (clone $qb)->count()->getQuery()->execute();
        $youtubeDocuments = $qb->getQuery()->execute();

        $progress = $this->startProgress($io, sprintf('Processing Youtube documents (%d)', $total), $total);

        $rows = [];
        $iteration = 0;

        foreach ($youtubeDocuments as $youtubeDocument) {
            // @var Youtube $youtubeDocument
            $this->maybeClearBatch($iteration);
            ++$iteration;
            ++$totals['checked'];
            $progress->advance();

            $row = $this->reconcileYoutubeDocument($youtubeDocument, $apply, $totals);
            if (null !== $row) {
                $rows[] = $row;
            }
        }

        $this->finishBatch($io, $progress);

        return $rows;
    }

    private function reconcileYoutubeDocument(Youtube $youtubeDocument, bool $apply, array &$totals): ?array
    {
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
            '_id' => new ObjectId($youtubeDocument->getMultimediaObjectId()),
        ]);

        $statusLabel = self::STATUS_LABELS[$youtubeDocument->getStatus()] ?? (string) $youtubeDocument->getStatus();

        if (!$multimediaObject) {
            ++$totals['mmo_missing'];

            return [$youtubeDocument->getMultimediaObjectId(), $youtubeDocument->getId(), $statusLabel, '-', 'MMO not found — skip'];
        }

        if (in_array($youtubeDocument->getStatus(), self::STRIP_STATUSES, true)) {
            $removed = $this->stripYoutubeTags($multimediaObject, $youtubeDocument, $apply);
            if (empty($removed)) {
                ++$totals['noop'];

                return null;
            }
            $totals['removed'] += count($removed);

            return [$multimediaObject->getId(), $youtubeDocument->getId(), $statusLabel, 'strip', implode(', ', $removed)];
        }

        $actions = $this->ensureYoutubeTags($multimediaObject, $youtubeDocument, $apply);
        if (null === $actions) {
            ++$totals['unresolvable'];

            return [
                $multimediaObject->getId(),
                $youtubeDocument->getId(),
                $statusLabel,
                '-',
                sprintf('Account tag not resolvable for login "%s"', $youtubeDocument->getYoutubeAccount()),
            ];
        }

        if (empty($actions['added']) && empty($actions['removed'])) {
            ++$totals['noop'];

            return null;
        }

        $totals['added'] += count($actions['added']);
        $totals['removed'] += count($actions['removed']);

        return [
            $multimediaObject->getId(),
            $youtubeDocument->getId(),
            $statusLabel,
            $this->actionLabel($actions),
            $this->formatActionDetail($actions),
        ];
    }

    private function processOrphanMmos(bool $apply, array &$totals, SymfonyStyle $io): array
    {
        $assignedIds = $this->collectAssignedMmoIds();

        $qb = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('tags.cod')->equals(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE)
        ;
        $total = (clone $qb)->count()->getQuery()->execute();
        $multimediaObjects = $qb->getQuery()->execute();

        $progress = $this->startProgress($io, sprintf('Scanning for orphan MMOs (%d candidates)', $total), $total);

        $rows = [];
        $iteration = 0;

        foreach ($multimediaObjects as $multimediaObject) {
            // @var MultimediaObject $multimediaObject
            $this->maybeClearBatch($iteration);
            ++$iteration;
            $progress->advance();

            if (isset($assignedIds[(string) $multimediaObject->getId()])) {
                continue;
            }

            $removed = $this->stripYoutubeTags($multimediaObject, null, $apply);
            if (empty($removed)) {
                continue;
            }

            ++$totals['orphans'];
            $totals['removed'] += count($removed);
            $rows[] = [$multimediaObject->getId(), 'strip', implode(', ', $removed)];
        }

        $this->finishBatch($io, $progress);

        return $rows;
    }

    /**
     * @return array<string, true>
     */
    private function collectAssignedMmoIds(): array
    {
        $assigned = [];
        $cursor = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->select('multimediaObjectId')
            ->hydrate(false)
            ->getQuery()
            ->execute()
        ;
        foreach ($cursor as $row) {
            if (!empty($row['multimediaObjectId'])) {
                $assigned[(string) $row['multimediaObjectId']] = true;
            }
        }

        return $assigned;
    }

    /**
     * @return string[] cods removed (annotated with x{N} when multiple copies were removed)
     */
    private function stripYoutubeTags(MultimediaObject $multimediaObject, ?Youtube $youtubeDocument, bool $apply): array
    {
        $tagsToRemove = [];
        foreach ($multimediaObject->getTags() as $embeddedTag) {
            $cod = $embeddedTag->getCod();
            $reference = null;

            if ($cod === $this->puchYoutubeTag->getCod()) {
                $reference = $this->puchYoutubeTag;
            } elseif ($embeddedTag->equalsOrDescendantOf($this->youtubeRootTag)) {
                $reference = $tagsToRemove[$cod]['tag']
                    ?? $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $cod]);
            }

            if (null === $reference) {
                continue;
            }

            if (!isset($tagsToRemove[$cod])) {
                $tagsToRemove[$cod] = ['tag' => $reference, 'count' => 0];
            }
            ++$tagsToRemove[$cod]['count'];
        }

        if (empty($tagsToRemove)) {
            return [];
        }

        if ($apply) {
            $mutation = function () use ($multimediaObject, $tagsToRemove) {
                foreach ($tagsToRemove as $entry) {
                    for ($i = 0; $i < $entry['count']; ++$i) {
                        $this->tagService->removeOneTag($multimediaObject, $entry['tag'], false);
                    }
                }
            };

            if (null !== $youtubeDocument) {
                $this->applyTagChanges($youtubeDocument, $mutation);
            } else {
                $mutation();
                $this->documentManager->flush();
            }
        }

        return $this->formatRemovedCods($tagsToRemove);
    }

    /**
     * @return array{added: string[], removed: string[]}|null Tag cods added/removed, or null if the account tag cannot be resolved
     */
    private function ensureYoutubeTags(MultimediaObject $multimediaObject, Youtube $youtubeDocument, bool $apply): ?array
    {
        $accountLogin = $youtubeDocument->getYoutubeAccount();
        if (!is_string($accountLogin) || '' === $accountLogin) {
            return null;
        }

        $accountTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $accountLogin,
        ]);

        if (!$accountTag) {
            return null;
        }

        $expected = $this->buildExpectedTags($accountTag, $youtubeDocument);
        [$tagsToAdd, $tagsToRemove] = $this->reconcileEmbeddedTags($multimediaObject, $expected);

        if (empty($tagsToAdd) && empty($tagsToRemove)) {
            return ['added' => [], 'removed' => []];
        }

        if ($apply) {
            $this->applyTagChanges($youtubeDocument, function () use ($multimediaObject, $tagsToAdd, $tagsToRemove) {
                foreach ($tagsToRemove as $entry) {
                    for ($i = 0; $i < $entry['count']; ++$i) {
                        $this->tagService->removeOneTag($multimediaObject, $entry['tag'], false);
                    }
                }
                foreach ($tagsToAdd as $tag) {
                    $this->tagService->addTag($multimediaObject, $tag, false);
                }
            });
        }

        return [
            'added' => array_map(static fn (Tag $tag) => $tag->getCod(), $tagsToAdd),
            'removed' => $this->formatRemovedCods($tagsToRemove),
        ];
    }

    /**
     * @return array<string, Tag>
     */
    private function buildExpectedTags(Tag $accountTag, Youtube $youtubeDocument): array
    {
        $expected = [
            $this->puchYoutubeTag->getCod() => $this->puchYoutubeTag,
            $accountTag->getCod() => $accountTag,
        ];

        foreach ($youtubeDocument->getPlaylists() as $playlistCod => $youtubePlaylistId) {
            $playlistTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'cod' => $playlistCod,
                'parent.$id' => new ObjectId($accountTag->getId()),
            ]);
            if ($playlistTag) {
                $expected[$playlistTag->getCod()] = $playlistTag;
            }
        }

        return $expected;
    }

    /**
     * @param array<string, Tag> $expected
     *
     * @return array{0: Tag[], 1: array<string, array{tag: Tag, count: int}>} [$tagsToAdd, $tagsToRemove]
     */
    private function reconcileEmbeddedTags(MultimediaObject $multimediaObject, array $expected): array
    {
        $stats = [];

        foreach ($multimediaObject->getTags() as $embeddedTag) {
            $cod = $embeddedTag->getCod();
            $reference = null;

            if ($cod === $this->puchYoutubeTag->getCod()) {
                $reference = $this->puchYoutubeTag;
            } elseif ($embeddedTag->equalsOrDescendantOf($this->youtubeRootTag)) {
                if (isset($expected[$cod])) {
                    $reference = $expected[$cod];
                } else {
                    $reference = $stats[$cod]['tag']
                        ?? $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $cod]);
                }
            }

            if (null === $reference) {
                continue;
            }

            if (!isset($stats[$cod])) {
                $stats[$cod] = ['tag' => $reference, 'fresh' => 0, 'stale' => 0];
            }

            if ($embeddedTag->getPath() === $reference->getPath()) {
                ++$stats[$cod]['fresh'];
            } else {
                ++$stats[$cod]['stale'];
            }
        }

        $tagsToAdd = [];
        $tagsToRemove = [];

        foreach ($expected as $cod => $expectedTag) {
            $s = $stats[$cod] ?? ['fresh' => 0, 'stale' => 0];
            if (0 === $s['fresh']) {
                $tagsToAdd[] = $expectedTag;
            }
            $extras = max(0, $s['fresh'] - 1) + $s['stale'];
            if ($extras > 0) {
                $tagsToRemove[$cod] = ['tag' => $expectedTag, 'count' => $extras];
            }
        }

        foreach ($stats as $cod => $s) {
            if (isset($expected[$cod])) {
                continue;
            }
            $tagsToRemove[$cod] = ['tag' => $s['tag'], 'count' => $s['fresh'] + $s['stale']];
        }

        return [$tagsToAdd, $tagsToRemove];
    }

    /**
     * Apply a mutation and neutralize the multimediaObjectUpdateDate side effect
     * from UpdateListener so the metadata-sync cron is not falsely triggered.
     */
    private function applyTagChanges(Youtube $youtubeDocument, callable $mutate): void
    {
        $previousUpdateDate = $youtubeDocument->getMultimediaObjectUpdateDate();

        $mutate();
        $this->documentManager->flush();

        $youtubeDocument->setMultimediaObjectUpdateDate($previousUpdateDate);
        $this->documentManager->flush();
    }

    private function detectMmoInconsistencies(?string $filterAccount, SymfonyStyle $io): array
    {
        $qb = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('tags.cod')->equals(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE)
        ;
        $total = (clone $qb)->count()->getQuery()->execute();
        $multimediaObjects = $qb->getQuery()->execute();

        $progress = $this->startProgress($io, sprintf('Checking MultimediaObject consistency (%d)', $total), $total);

        $rows = [];
        $iteration = 0;

        foreach ($multimediaObjects as $multimediaObject) {
            // @var MultimediaObject $multimediaObject
            $this->maybeClearBatch($iteration);
            ++$iteration;
            $progress->advance();

            $row = $this->inspectMmo($multimediaObject, $filterAccount);
            if (null !== $row) {
                $rows[] = $row;
            }
        }

        $this->finishBatch($io, $progress);

        return $rows;
    }

    private function inspectMmo(MultimediaObject $multimediaObject, ?string $filterAccount): ?array
    {
        $embeddedAccountTag = null;
        foreach ($multimediaObject->getTags() as $embeddedTag) {
            if ($embeddedTag->isChildOf($this->youtubeRootTag)) {
                $embeddedAccountTag = $embeddedTag;

                break;
            }
        }

        if (null === $embeddedAccountTag) {
            if ($filterAccount) {
                return null;
            }

            return [$multimediaObject->getId(), '-', 'No YouTube account tag embedded in MultimediaObject'];
        }

        $accountTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => $embeddedAccountTag->getCod(),
        ]);

        if (!$accountTag) {
            if ($filterAccount) {
                return null;
            }

            return [
                $multimediaObject->getId(),
                (string) $embeddedAccountTag->getCod(),
                sprintf('Account Tag (cod: %s) does not exist in DB', $embeddedAccountTag->getCod()),
            ];
        }

        $tagLogin = $accountTag->getProperty('login');

        if ($filterAccount && $tagLogin !== $filterAccount) {
            return null;
        }

        if (!is_string($tagLogin) || '' === $tagLogin) {
            return [
                $multimediaObject->getId(),
                (string) $accountTag->getCod(),
                'Account Tag has no "login" property — would cause TypeError on upload',
            ];
        }

        return null;
    }

    private function actionLabel(array $actions): string
    {
        if (empty($actions['removed'])) {
            return 'add';
        }
        if (empty($actions['added'])) {
            return 'remove';
        }

        return 'sync';
    }

    /**
     * @param array<string, array{tag: Tag, count: int}> $tagsToRemove
     *
     * @return string[]
     */
    private function formatRemovedCods(array $tagsToRemove): array
    {
        $out = [];
        foreach ($tagsToRemove as $cod => $entry) {
            $out[] = $entry['count'] > 1 ? sprintf('%s x%d', $cod, $entry['count']) : $cod;
        }

        return $out;
    }

    private function formatActionDetail(array $actions): string
    {
        $parts = [];
        if (!empty($actions['added'])) {
            $parts[] = '+'.implode(', +', $actions['added']);
        }
        if (!empty($actions['removed'])) {
            $parts[] = '-'.implode(', -', $actions['removed']);
        }

        return implode(' | ', $parts);
    }

    private function startProgress(SymfonyStyle $io, string $sectionTitle, int $total): ProgressBar
    {
        $io->section($sectionTitle);
        $progress = $io->createProgressBar($total);
        $progress->setFormat('verbose');
        $progress->start();

        return $progress;
    }

    private function maybeClearBatch(int $iteration): void
    {
        if ($iteration > 0 && 0 === $iteration % self::BATCH_SIZE) {
            $this->documentManager->clear();
            $this->loadRootTags();
        }
    }

    private function finishBatch(SymfonyStyle $io, ProgressBar $progress): void
    {
        $this->documentManager->flush();
        $this->documentManager->clear();
        $this->loadRootTags();

        $progress->finish();
        $io->newLine(2);
    }

    private function renderReport(
        SymfonyStyle $io,
        array $syncRows,
        array $orphanRows,
        array $inconsistencyRows,
        array $totals,
        bool $apply,
        bool $reportOnly
    ): void {
        if (!empty($syncRows)) {
            $io->section('Sync actions on Youtube documents');
            $io->table(['MMO ID', 'Youtube Doc ID', 'Doc status', 'Action', 'Tags'], $syncRows);
        }

        if (!empty($orphanRows)) {
            $io->section('Stripped orphan MultimediaObjects (PUCHYOUTUBE without Youtube doc)');
            $io->table(['MMO ID', 'Action', 'Tags'], $orphanRows);
        }

        if (!empty($inconsistencyRows)) {
            $io->section('MultimediaObjects with PUCHYOUTUBE — broken account tag');
            $io->table(['MMO ID', 'Account Tag cod', 'Problem'], $inconsistencyRows);
        }

        $io->section('Summary');
        $io->writeln(sprintf(
            'Checked: %d | Added: %d | Removed: %d | Orphans stripped: %d | No-op: %d | MMO missing: %d | Unresolvable: %d | Inconsistencies: %d',
            $totals['checked'],
            $totals['added'],
            $totals['removed'],
            $totals['orphans'],
            $totals['noop'],
            $totals['mmo_missing'],
            $totals['unresolvable'],
            count($inconsistencyRows)
        ));

        if (!$apply && !$reportOnly) {
            $io->note('Dry-run mode. Re-run with --force to apply the changes above.');
        } elseif ($apply) {
            $io->success('Sync applied.');
        }
    }
}
