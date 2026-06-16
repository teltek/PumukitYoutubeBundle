<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only preview of what the YouTube cron commands would do on the next run.
 *
 * Each category mirrors the candidate query of the corresponding cron command.
 * If a cron query changes, the matching section here MUST be updated.
 */
class OperationsPreviewCommand extends Command
{
    private const SAMPLE_LIMIT = 20;

    private const CATEGORIES = [
        'upload',
        'delete',
        'metadata',
        'status',
        'pending',
        'playlist-sync',
        'playlist-update',
        'caption-upload',
        'caption-delete',
        'orphans',
        'errors',
    ];

    private DocumentManager $documentManager;
    private YoutubeConfigurationService $youtubeConfigurationService;

    public function __construct(
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService
    ) {
        parent::__construct();
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:operations:preview')
            ->setDescription('Preview pending YouTube cron operations without executing them')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Limit preview to a specific account login')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Comma-separated subset of categories ('.implode(',', self::CATEGORIES).')')
            ->addOption('detail', null, InputOption::VALUE_NONE, 'Show sample MMO/document IDs per category')
            ->setHelp(
                <<<'EOT'
Read-only snapshot of what each YouTube cron command would pick up on its next run.
Useful before triggering crons (or after a normalization) to see the queue.

  pumukit:youtube:operations:preview
  pumukit:youtube:operations:preview --account=urjc-p-youtube
  pumukit:youtube:operations:preview --category=upload,delete --detail

Categories:
  upload          MMOs awaiting upload (new + retry from ERROR + REMOVED re-upload)
  delete          Youtube docs queued for deletion (STATUS_TO_DELETE)
  metadata        Youtube docs with multimediaObjectUpdateDate > syncMetadataDate
  status          Youtube docs subject to status polling
  pending         Youtube docs in UPLOADING/PROCESSING (pending status poll)
  playlist-sync   Local playlist Tags per account (no YouTube API call)
  playlist-update MMOs eligible for playlist membership sync
  caption-upload  Eligible MMOs for caption sync (no YouTube API call)
  caption-delete  Eligible MMOs for caption cleanup (no YouTube API call)
  orphans         Youtube docs pointing to a non-existent MMO
  errors          Youtube docs blocked by stored errors
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filterAccount = $input->getOption('account');
        $showDetail = (bool) $input->getOption('detail');
        $categoriesArg = $input->getOption('category');

        $categories = self::CATEGORIES;
        if (is_string($categoriesArg) && '' !== $categoriesArg) {
            $requested = array_map('trim', explode(',', $categoriesArg));
            $unknown = array_diff($requested, self::CATEGORIES);
            if (!empty($unknown)) {
                $io->error(sprintf('Unknown category: %s. Valid: %s', implode(',', $unknown), implode(',', self::CATEGORIES)));

                return Command::FAILURE;
            }
            $categories = $requested;
        }

        $io->title('YouTube Pending Operations — Preview');
        if ($filterAccount) {
            $io->writeln(sprintf('<comment>Account filter:</comment> %s', $filterAccount));
        }
        if (!$this->youtubeConfigurationService->uploadRemovedVideos()) {
            $io->writeln('<comment>Config:</comment> uploadRemovedVideos=false (REMOVED re-upload not counted)');
        }
        $io->newLine();

        $sections = [];

        foreach ($categories as $category) {
            $sections[$category] = $this->collect($category, $filterAccount);
        }

        $this->renderSections($io, $sections, $showDetail);
        $this->renderTotals($io, $sections);

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{label: string, count: int, samples: string[]}>
     */
    private function collect(string $category, ?string $filterAccount): array
    {
        switch ($category) {
            case 'upload':
                return $this->collectUpload($filterAccount);
            case 'delete':
                return $this->collectDelete($filterAccount);
            case 'metadata':
                return $this->collectMetadata($filterAccount);
            case 'status':
                return $this->collectStatus($filterAccount);
            case 'pending':
                return $this->collectPending($filterAccount);
            case 'playlist-sync':
                return $this->collectPlaylistSync($filterAccount);
            case 'playlist-update':
                return $this->collectPlaylistUpdate($filterAccount);
            case 'caption-upload':
                return $this->collectCaptionEligible($filterAccount, 'upload');
            case 'caption-delete':
                return $this->collectCaptionEligible($filterAccount, 'delete');
            case 'orphans':
                return $this->collectOrphans($filterAccount);
            case 'errors':
                return $this->collectErrors($filterAccount);
        }

        return [];
    }

    /**
     * Mirrors VideoUploadCommand::createMultimediaObjectsToUploadQueryBuilder() and its three
     * paths: new uploads + retry from ERROR + (if config) re-upload REMOVED.
     */
    private function collectUpload(?string $filterAccount): array
    {
        $rows = [];

        $newQb = $this->buildUploadCandidateQb()->field('properties.youtube')->exists(false);
        $rows[] = $this->summarize('New uploads (no Youtube doc yet)', $newQb);

        $errorIds = $this->collectMmoIdsByYoutubeStatus([Youtube::STATUS_ERROR], $filterAccount);
        if (!empty($errorIds)) {
            $retryQb = $this->buildUploadCandidateQb()->field('_id')->in($this->toObjectIds($errorIds));
            $rows[] = $this->summarize('Retry uploads (Youtube doc in ERROR)', $retryQb);
        } else {
            $rows[] = ['label' => 'Retry uploads (Youtube doc in ERROR)', 'count' => 0, 'samples' => []];
        }

        if ($this->youtubeConfigurationService->uploadRemovedVideos()) {
            $removedIds = $this->collectMmoIdsByYoutubeStatus([Youtube::STATUS_REMOVED], $filterAccount);
            if (!empty($removedIds)) {
                $removedQb = $this->buildUploadCandidateQb()->field('_id')->in($this->toObjectIds($removedIds));
                $rows[] = $this->summarize('Re-upload REMOVED (config: uploadRemovedVideos)', $removedQb);
            } else {
                $rows[] = ['label' => 'Re-upload REMOVED (config: uploadRemovedVideos)', 'count' => 0, 'samples' => []];
            }
        }

        if (null !== $filterAccount) {
            $rows = $this->filterRowsByAccount($rows, $filterAccount, $this->buildUploadCandidateQb());
        }

        return $rows;
    }

    /**
     * Mirrors VideoDeleteCommand::$orphanVideos — Youtube docs in STATUS_TO_DELETE.
     */
    private function collectDelete(?string $filterAccount): array
    {
        $qb = $this->buildYoutubeQb([Youtube::STATUS_TO_DELETE], $filterAccount);

        return [$this->summarizeYoutubeDocs('Youtube docs queued for deletion', $qb)];
    }

    /**
     * Mirrors YoutubeRepository::getNotMetadataUpdatedQueryBuilder() — date drift.
     */
    private function collectMetadata(?string $filterAccount): array
    {
        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->where('this.multimediaObjectUpdateDate > this.syncMetadataDate')
        ;
        if (null !== $filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }

        return [$this->summarizeYoutubeDocs('Metadata drift (update pending)', $qb)];
    }

    /**
     * Mirrors VideoUpdateStatusCommand: excludes REMOVED/DUPLICATED/UPLOADING/PROCESSING/TO_REVIEW.
     */
    private function collectStatus(?string $filterAccount): array
    {
        $excluded = [
            Youtube::STATUS_REMOVED,
            Youtube::STATUS_DUPLICATED,
            Youtube::STATUS_UPLOADING,
            Youtube::STATUS_PROCESSING,
            Youtube::STATUS_TO_REVIEW,
        ];
        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->field('status')->notIn($excluded)
        ;
        if (null !== $filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }

        return [$this->summarizeYoutubeDocs('Status polling candidates', $qb)];
    }

    /**
     * Mirrors VideoUpdatePendingStatusCommand — STATUS_UPLOADING/PROCESSING only.
     */
    private function collectPending(?string $filterAccount): array
    {
        $qb = $this->buildYoutubeQb([Youtube::STATUS_UPLOADING, Youtube::STATUS_PROCESSING], $filterAccount);

        return [$this->summarizeYoutubeDocs('Pending status (UPLOADING/PROCESSING)', $qb)];
    }

    /**
     * No API call: list account Tags and their local playlist child count.
     * Mirrors PlaylistSyncCommand::getAllYouTubeAccounts() scope.
     */
    private function collectPlaylistSync(?string $filterAccount): array
    {
        $accountCriteria = ['properties.login' => ['$exists' => true]];
        if (null !== $filterAccount) {
            $accountCriteria['properties.login'] = $filterAccount;
        }
        $accounts = $this->documentManager->getRepository(Tag::class)->findBy($accountCriteria);

        $rows = [];
        foreach ($accounts as $accountTag) {
            $playlistCount = $this->documentManager->getRepository(Tag::class)->createQueryBuilder()
                ->field('parent.$id')->equals(new ObjectId($accountTag->getId()))
                ->count()
                ->getQuery()
                ->execute()
            ;
            $login = (string) $accountTag->getProperty('login');
            $rows[] = [
                'label' => sprintf('Account "%s": local playlists registered', $login),
                'count' => (int) $playlistCount,
                'samples' => [],
            ];
        }

        if (empty($rows)) {
            $rows[] = ['label' => 'No YouTube account Tags found', 'count' => 0, 'samples' => []];
        }

        return $rows;
    }

    /**
     * Mirrors PlaylistUpdateCommand: MMOs with properties.youtube set (non-pumukit1, non-imported).
     */
    private function collectPlaylistUpdate(?string $filterAccount): array
    {
        $qb = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('properties.origin')->notEqual('youtube')
            ->field('properties.pumukit1id')->exists(false)
            ->field('properties.youtube')->exists(true)
        ;
        if (null !== $filterAccount) {
            $mmIds = $this->collectMmoIdsByYoutubeAccount($filterAccount);
            if (empty($mmIds)) {
                return [['label' => 'MMOs eligible for playlist update', 'count' => 0, 'samples' => []]];
            }
            $qb->field('_id')->in($this->toObjectIds($mmIds));
        }

        return [$this->summarize('MMOs eligible for playlist update', $qb)];
    }

    /**
     * No API call. Reports MMOs eligible for caption sync per the upload/delete cron filters
     * (the actual diff requires hitting YouTube to enumerate remote captions).
     */
    private function collectCaptionEligible(?string $filterAccount, string $kind): array
    {
        $pubTags = $this->youtubeConfigurationService->publicationChannelsTags();
        if (empty($pubTags)) {
            $pubTags = [PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE];
        }

        $statuses = $this->youtubeConfigurationService->syncStatus()
            ? [MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN]
            : [MultimediaObject::STATUS_PUBLISHED];

        $qb = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('properties.pumukit1id')->exists(false)
            ->field('properties.origin')->notEqual('youtube')
            ->field('properties.youtube')->exists(true)
            ->field('status')->in($statuses)
            ->field('embeddedBroadcast.type')->equals('public')
            ->field('tags.cod')->all($pubTags)
        ;

        if (null !== $filterAccount) {
            $mmIds = $this->collectMmoIdsByYoutubeAccount($filterAccount);
            if (empty($mmIds)) {
                return [['label' => sprintf('MMOs eligible for caption %s (diff requires API)', $kind), 'count' => 0, 'samples' => []]];
            }
            $qb->field('_id')->in($this->toObjectIds($mmIds));
        }

        return [$this->summarize(sprintf('MMOs eligible for caption %s (diff requires API)', $kind), $qb)];
    }

    private function collectOrphans(?string $filterAccount): array
    {
        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder();
        if (null !== $filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }
        $youtubeDocs = $qb->getQuery()->execute();

        $orphanIds = [];
        foreach ($youtubeDocs as $youtubeDocument) {
            // @var Youtube $youtubeDocument
            $mmoId = $youtubeDocument->getMultimediaObjectId();
            if (!$mmoId) {
                $orphanIds[] = (string) $youtubeDocument->getId();

                continue;
            }
            $exists = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
                ->field('_id')->equals(new ObjectId($mmoId))
                ->count()
                ->getQuery()
                ->execute()
            ;
            if (0 === (int) $exists) {
                $orphanIds[] = sprintf('%s→%s', (string) $youtubeDocument->getId(), $mmoId);
            }
        }

        return [[
            'label' => 'Youtube docs pointing to missing MMO',
            'count' => count($orphanIds),
            'samples' => array_slice($orphanIds, 0, self::SAMPLE_LIMIT),
        ]];
    }

    private function collectErrors(?string $filterAccount): array
    {
        $rows = [];
        $fields = ['error', 'metadataUpdateError', 'playlistUpdateError', 'captionUpdateError'];

        foreach ($fields as $field) {
            $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
                ->field($field)->exists(true)
                ->field($field)->notEqual(null)
            ;
            if (null !== $filterAccount) {
                $qb->field('youtubeAccount')->equals($filterAccount);
            }
            $rows[] = $this->summarizeYoutubeDocs(sprintf('Youtube docs with %s set', $field), $qb);
        }

        return $rows;
    }

    private function buildUploadCandidateQb()
    {
        $pubTags = $this->youtubeConfigurationService->publicationChannelsTags();
        if (empty($pubTags)) {
            $pubTags = [PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE];
        }

        $statuses = $this->youtubeConfigurationService->syncStatus()
            ? [MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN]
            : [MultimediaObject::STATUS_PUBLISHED];

        return $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('properties.pumukit1id')->exists(false)
            ->field('properties.origin')->notEqual('youtube')
            ->field('status')->in($statuses)
            ->field('embeddedBroadcast.type')->equals('public')
            ->field('tags.cod')->all($pubTags)
        ;
    }

    private function buildYoutubeQb(array $statuses, ?string $filterAccount)
    {
        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->field('status')->in($statuses)
        ;
        if (null !== $filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }

        return $qb;
    }

    /**
     * @return string[]
     */
    private function collectMmoIdsByYoutubeStatus(array $statuses, ?string $filterAccount): array
    {
        $qb = $this->buildYoutubeQb($statuses, $filterAccount)
            ->select('multimediaObjectId')
            ->hydrate(false)
        ;
        $ids = [];
        foreach ($qb->getQuery()->execute() as $row) {
            if (!empty($row['multimediaObjectId'])) {
                $ids[] = (string) $row['multimediaObjectId'];
            }
        }

        return $ids;
    }

    /**
     * @return string[]
     */
    private function collectMmoIdsByYoutubeAccount(string $accountLogin): array
    {
        $cursor = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->field('youtubeAccount')->equals($accountLogin)
            ->select('multimediaObjectId')
            ->hydrate(false)
            ->getQuery()
            ->execute()
        ;
        $ids = [];
        foreach ($cursor as $row) {
            if (!empty($row['multimediaObjectId'])) {
                $ids[] = (string) $row['multimediaObjectId'];
            }
        }

        return $ids;
    }

    /**
     * @param string[] $ids
     *
     * @return ObjectId[]
     */
    private function toObjectIds(array $ids): array
    {
        return array_map(static fn (string $id) => new ObjectId($id), $ids);
    }

    /**
     * @return array{label: string, count: int, samples: string[]}
     */
    private function summarize(string $label, $qb): array
    {
        $count = (int) (clone $qb)->count()->getQuery()->execute();
        $samples = [];
        if ($count > 0) {
            $cursor = (clone $qb)
                ->select('_id')
                ->hydrate(false)
                ->limit(self::SAMPLE_LIMIT)
                ->getQuery()
                ->execute()
            ;
            foreach ($cursor as $row) {
                $samples[] = (string) $row['_id'];
            }
        }

        return ['label' => $label, 'count' => $count, 'samples' => $samples];
    }

    /**
     * @return array{label: string, count: int, samples: string[]}
     */
    private function summarizeYoutubeDocs(string $label, $qb): array
    {
        $count = (int) (clone $qb)->count()->getQuery()->execute();
        $samples = [];
        if ($count > 0) {
            $cursor = (clone $qb)
                ->select('_id', 'multimediaObjectId')
                ->hydrate(false)
                ->limit(self::SAMPLE_LIMIT)
                ->getQuery()
                ->execute()
            ;
            foreach ($cursor as $row) {
                $samples[] = sprintf('%s→%s', (string) $row['_id'], (string) ($row['multimediaObjectId'] ?? '-'));
            }
        }

        return ['label' => $label, 'count' => $count, 'samples' => $samples];
    }

    /**
     * For upload paths: restrict an already-computed row set to MMOs whose YT doc lives on the
     * requested account. The "New uploads" path has no YT doc yet, so cannot be filtered.
     */
    private function filterRowsByAccount(array $rows, string $filterAccount, $candidateQbTemplate): array
    {
        $mmIdsOnAccount = $this->collectMmoIdsByYoutubeAccount($filterAccount);
        $accountObjectIds = $this->toObjectIds($mmIdsOnAccount);
        $filtered = [];

        foreach ($rows as $index => $row) {
            if (0 === $index) {
                $filtered[] = ['label' => $row['label'].' [not filterable by account]', 'count' => $row['count'], 'samples' => $row['samples']];

                continue;
            }
            if (empty($mmIdsOnAccount)) {
                $filtered[] = ['label' => $row['label'], 'count' => 0, 'samples' => []];

                continue;
            }
            $qb = (clone $candidateQbTemplate)->field('_id')->in($accountObjectIds);
            $filtered[] = $this->summarize($row['label'], $qb);
        }

        return $filtered;
    }

    /**
     * @param array<string, array<int, array{label: string, count: int, samples: string[]}>> $sections
     */
    private function renderSections(SymfonyStyle $io, array $sections, bool $showDetail): void
    {
        $headings = [
            'upload' => 'VIDEO UPLOAD',
            'delete' => 'VIDEO DELETE',
            'metadata' => 'VIDEO UPDATE METADATA',
            'status' => 'VIDEO UPDATE STATUS',
            'pending' => 'VIDEO UPDATE PENDING STATUS',
            'playlist-sync' => 'PLAYLIST SYNC (local Tag tree)',
            'playlist-update' => 'PLAYLIST UPDATE (MMO membership)',
            'caption-upload' => 'CAPTION UPLOAD',
            'caption-delete' => 'CAPTION DELETE',
            'orphans' => 'ORPHAN Youtube DOCUMENTS',
            'errors' => 'STORED ERRORS ON Youtube DOCUMENTS',
        ];

        foreach ($sections as $category => $rows) {
            $io->section($headings[$category] ?? strtoupper($category));
            $tableRows = [];
            foreach ($rows as $row) {
                $tableRows[] = [$row['label'], $row['count']];
            }
            $io->table(['Operation', 'Count'], $tableRows);

            if ($showDetail) {
                foreach ($rows as $row) {
                    if (empty($row['samples'])) {
                        continue;
                    }
                    $io->writeln(sprintf('<comment>%s</comment> sample IDs:', $row['label']));
                    foreach ($row['samples'] as $id) {
                        $io->writeln('  - '.$id);
                    }
                    if ($row['count'] > count($row['samples'])) {
                        $io->writeln(sprintf('  ... and %d more', $row['count'] - count($row['samples'])));
                    }
                    $io->newLine();
                }
            }
        }
    }

    /**
     * @param array<string, array<int, array{label: string, count: int, samples: string[]}>> $sections
     */
    private function renderTotals(SymfonyStyle $io, array $sections): void
    {
        $informationalCategories = ['playlist-sync', 'orphans', 'errors'];
        $actionable = 0;
        $informational = 0;

        foreach ($sections as $category => $rows) {
            $sum = 0;
            foreach ($rows as $row) {
                $sum += $row['count'];
            }
            if (in_array($category, $informationalCategories, true)) {
                $informational += $sum;
            } else {
                $actionable += $sum;
            }
        }

        $io->section('Summary');
        $io->writeln(sprintf('Actionable operations queued: <info>%d</info>', $actionable));
        $io->writeln(sprintf('Informational entries (orphans/errors/local playlists): <comment>%d</comment>', $informational));
        $io->note('This is a read-only preview. No data was modified.');
    }
}
