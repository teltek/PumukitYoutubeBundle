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
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AccountTagsCommand extends Command
{
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

    private DocumentManager $documentManager;
    private TagService $tagService;
    private YoutubeConfigurationService $youtubeConfigurationService;

    public function __construct(
        DocumentManager $documentManager,
        TagService $tagService,
        YoutubeConfigurationService $youtubeConfigurationService
    ) {
        $this->documentManager = $documentManager;
        $this->tagService = $tagService;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:account:tags')
            ->setDescription('Inspect and reconcile YouTube account/playlist tags between Youtube documents and their MultimediaObjects')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Limit to a specific account login')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply the proposed actions (otherwise dry-run)')
            ->addOption('report-only', null, InputOption::VALUE_NONE, 'Only run the consistency report on MultimediaObjects, skip sync proposals/actions')
            ->setHelp(
                <<<'EOT'
Unified consistency + sync command for YouTube account/playlist tags.

The Youtube document is the source of truth. For each Youtube document, the
linked MultimediaObject's embedded tags are reconciled:

- If status is REMOVED or TO_DELETE:
    Strip the YouTube publication channel tag (PUCHYOUTUBE), the account tag
    and every playlist tag (descendant of the YOUTUBE root) from the MMO.

- Otherwise:
    Add the account tag (resolved by Youtube document's youtubeAccount login)
    and every playlist tag listed in the Youtube document's playlists map to
    the MMO when missing.

In parallel, an independent consistency check is run on every MultimediaObject
tagged with PUCHYOUTUBE that surfaces broken account tags (no embedded YT
child, account tag missing in DB, or missing "login" property). Those will
cause TypeErrors on upload (GoogleAccountService::createClientWithAccessToken).

Side-effect note: applying tag changes triggers the multimediaobject.update
event, which UpdateListener uses to mark the Youtube document dirty for the
metadata-sync cron. This command restores multimediaObjectUpdateDate to its
previous value after each apply so the cron is not falsely triggered.

  php bin/console pumukit:youtube:account:tags
  php bin/console pumukit:youtube:account:tags --account=myaccount
  php bin/console pumukit:youtube:account:tags --force
  php bin/console pumukit:youtube:account:tags --report-only
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

        $mode = $apply ? 'APPLY' : ($reportOnly ? 'report-only' : 'dry-run');
        $io->title(sprintf('YouTube Account Tags — %s', $mode));

        $this->renderConfiguration($io);

        $youtubeRootTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);
        $puchYoutubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE,
        ]);

        if (!$youtubeRootTag || !$puchYoutubeTag) {
            $io->error('YOUTUBE or PUCHYOUTUBE tag missing. Run pumukit:youtube:init:tags first.');

            return Command::FAILURE;
        }

        $totals = [
            'checked' => 0,
            'added' => 0,
            'removed' => 0,
            'noop' => 0,
            'mmo_missing' => 0,
            'unresolvable' => 0,
        ];

        $syncRows = [];
        if (!$reportOnly) {
            $syncRows = $this->processYoutubeDocuments(
                $youtubeRootTag,
                $puchYoutubeTag,
                $filterAccount,
                $apply,
                $totals
            );
        }

        $inconsistencyRows = $this->detectMmoInconsistencies($youtubeRootTag, $filterAccount);

        if (!empty($syncRows)) {
            $io->section('Sync actions on Youtube documents');
            $io->table(['MMO ID', 'Youtube Doc ID', 'Doc status', 'Action', 'Tags'], $syncRows);
        }

        if (!empty($inconsistencyRows)) {
            $io->section('MultimediaObjects with PUCHYOUTUBE — broken account tag');
            $io->table(['MMO ID', 'Account Tag cod', 'Problem'], $inconsistencyRows);
        }

        $io->section('Summary');
        $io->writeln(sprintf(
            'Checked: %d | Added: %d | Removed: %d | No-op: %d | MMO missing: %d | Unresolvable account: %d | MMO inconsistencies: %d',
            $totals['checked'],
            $totals['added'],
            $totals['removed'],
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

        return Command::SUCCESS;
    }

    private function processYoutubeDocuments(
        Tag $youtubeRootTag,
        Tag $puchYoutubeTag,
        ?string $filterAccount,
        bool $apply,
        array &$totals
    ): array {
        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder();
        if ($filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }
        $youtubeDocuments = $qb->getQuery()->execute();

        $stripStatuses = [Youtube::STATUS_REMOVED, Youtube::STATUS_TO_DELETE];
        $rows = [];

        foreach ($youtubeDocuments as $youtubeDocument) {
            // @var Youtube $youtubeDocument
            ++$totals['checked'];

            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                '_id' => new ObjectId($youtubeDocument->getMultimediaObjectId()),
            ]);

            $statusLabel = self::STATUS_LABELS[$youtubeDocument->getStatus()] ?? (string) $youtubeDocument->getStatus();

            if (!$multimediaObject) {
                ++$totals['mmo_missing'];
                $rows[] = [
                    $youtubeDocument->getMultimediaObjectId(),
                    $youtubeDocument->getId(),
                    $statusLabel,
                    '-',
                    'MMO not found — skip',
                ];

                continue;
            }

            if (in_array($youtubeDocument->getStatus(), $stripStatuses, true)) {
                $actions = $this->stripYoutubeTags($multimediaObject, $youtubeDocument, $youtubeRootTag, $puchYoutubeTag, $apply);
                if (empty($actions)) {
                    ++$totals['noop'];

                    continue;
                }
                $totals['removed'] += count($actions);
                $rows[] = [
                    $multimediaObject->getId(),
                    $youtubeDocument->getId(),
                    $statusLabel,
                    'strip',
                    implode(', ', $actions),
                ];

                continue;
            }

            $actions = $this->ensureYoutubeTags($multimediaObject, $youtubeDocument, $puchYoutubeTag, $apply);
            if (null === $actions) {
                ++$totals['unresolvable'];
                $rows[] = [
                    $multimediaObject->getId(),
                    $youtubeDocument->getId(),
                    $statusLabel,
                    '-',
                    sprintf('Account tag not resolvable for login "%s"', (string) $youtubeDocument->getYoutubeAccount()),
                ];

                continue;
            }

            if (empty($actions)) {
                ++$totals['noop'];

                continue;
            }
            $totals['added'] += count($actions);
            $rows[] = [
                $multimediaObject->getId(),
                $youtubeDocument->getId(),
                $statusLabel,
                'add',
                implode(', ', $actions),
            ];
        }

        return $rows;
    }

    /**
     * @return string[] List of removed tag cods. Empty if nothing to do.
     */
    private function stripYoutubeTags(
        MultimediaObject $multimediaObject,
        Youtube $youtubeDocument,
        Tag $youtubeRootTag,
        Tag $puchYoutubeTag,
        bool $apply
    ): array {
        $tagsToRemove = [];
        foreach ($multimediaObject->getTags() as $embeddedTag) {
            $cod = $embeddedTag->getCod();
            if ($cod === $puchYoutubeTag->getCod()) {
                $tagsToRemove[$cod] = $puchYoutubeTag;

                continue;
            }
            if ($embeddedTag->equalsOrDescendantOf($youtubeRootTag)) {
                $realTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $cod]);
                if ($realTag) {
                    $tagsToRemove[$cod] = $realTag;
                }
            }
        }

        if (empty($tagsToRemove)) {
            return [];
        }

        if ($apply) {
            $this->applyTagChanges($multimediaObject, $youtubeDocument, function () use ($multimediaObject, $tagsToRemove) {
                foreach ($tagsToRemove as $tag) {
                    $this->tagService->removeOneTag($multimediaObject, $tag, false);
                }
            });
        }

        return array_keys($tagsToRemove);
    }

    /**
     * @return string[]|null added tag cods, or null if the account tag cannot be resolved
     */
    private function ensureYoutubeTags(MultimediaObject $multimediaObject, Youtube $youtubeDocument, Tag $puchYoutubeTag, bool $apply): ?array
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

        $tagsToAdd = [];

        if (!$multimediaObject->containsTag($puchYoutubeTag)) {
            $tagsToAdd[] = $puchYoutubeTag;
        }

        if (!$multimediaObject->containsTag($accountTag)) {
            $tagsToAdd[] = $accountTag;
        }

        foreach ($youtubeDocument->getPlaylists() as $playlistCod => $youtubePlaylistId) {
            $playlistTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'cod' => $playlistCod,
                'parent.$id' => new ObjectId($accountTag->getId()),
            ]);
            if (!$playlistTag) {
                continue;
            }
            if ($multimediaObject->containsTag($playlistTag)) {
                continue;
            }
            $tagsToAdd[] = $playlistTag;
        }

        if (empty($tagsToAdd)) {
            return [];
        }

        if ($apply) {
            $this->applyTagChanges($multimediaObject, $youtubeDocument, function () use ($multimediaObject, $tagsToAdd) {
                foreach ($tagsToAdd as $tag) {
                    $this->tagService->addTag($multimediaObject, $tag, false);
                }
            });
        }

        return array_map(static fn (Tag $tag) => $tag->getCod(), $tagsToAdd);
    }

    /**
     * Apply a TagService mutation and neutralize the multimediaObjectUpdateDate
     * side-effect from UpdateListener so the metadata-sync cron is not falsely
     * triggered for a change we initiated ourselves.
     */
    private function applyTagChanges(MultimediaObject $multimediaObject, Youtube $youtubeDocument, callable $mutate): void
    {
        $previousUpdateDate = $youtubeDocument->getMultimediaObjectUpdateDate();

        $mutate();
        $this->documentManager->flush();

        $youtubeDocument->setMultimediaObjectUpdateDate($previousUpdateDate);
        $this->documentManager->flush();
    }

    private function detectMmoInconsistencies(Tag $youtubeRootTag, ?string $filterAccount): array
    {
        $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('tags.cod')->equals(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE)
            ->getQuery()
            ->execute()
        ;

        $rows = [];

        foreach ($multimediaObjects as $multimediaObject) {
            // @var MultimediaObject $multimediaObject
            $embeddedAccountTag = null;
            foreach ($multimediaObject->getTags() as $embeddedTag) {
                if ($embeddedTag->isChildOf($youtubeRootTag)) {
                    $embeddedAccountTag = $embeddedTag;

                    break;
                }
            }

            if (null === $embeddedAccountTag) {
                if ($filterAccount) {
                    continue;
                }
                $rows[] = [
                    $multimediaObject->getId(),
                    '-',
                    'No YouTube account tag embedded in MultimediaObject',
                ];

                continue;
            }

            $accountTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'cod' => $embeddedAccountTag->getCod(),
            ]);

            if (!$accountTag) {
                if ($filterAccount) {
                    continue;
                }
                $rows[] = [
                    $multimediaObject->getId(),
                    (string) $embeddedAccountTag->getCod(),
                    sprintf('Account Tag (cod: %s) does not exist in DB', $embeddedAccountTag->getCod()),
                ];

                continue;
            }

            $tagLogin = $accountTag->getProperty('login');

            if ($filterAccount && $tagLogin !== $filterAccount) {
                continue;
            }

            if (!is_string($tagLogin) || '' === $tagLogin) {
                $rows[] = [
                    $multimediaObject->getId(),
                    (string) $accountTag->getCod(),
                    'Account Tag has no "login" property — would cause TypeError on upload',
                ];
            }
        }

        return $rows;
    }

    private function renderConfiguration(SymfonyStyle $io): void
    {
        $io->section('Bundle configuration');

        $config = $this->youtubeConfigurationService->getBundleConfiguration();

        $rows = [];
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value) ?: '(empty)';
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $rows[] = [$key, (string) $value];
        }

        $io->table(['Parameter', 'Value'], $rows);
    }
}
