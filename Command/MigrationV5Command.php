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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationV5Command extends Command
{
    private const BATCH_SIZE = 50;

    private $documentManager;
    private TagService $tagService;
    private $pubChannelProperties = [
        'modal_path' => 'pumukityoutube_modal_index',
        'advanced_configuration' => 'pumukityoutube_advance_configuration_index',
    ];

    private $accountName;
    private $youtubeTagProperties = [
        'hide_in_tag_group' => true,
    ];

    private $force;

    private $accountStorage;

    private $output;
    private $step;
    private $results = [];

    public function __construct(DocumentManager $documentManager, TagService $tagService, string $accountStorage)
    {
        $this->documentManager = $documentManager;
        $this->tagService = $tagService;
        $this->accountStorage = $accountStorage;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:migration:v5:schema')
            ->setDescription('Migrate schema from PumukitYoutubeBundle single account to PumukitYoutubeBundle multiple account')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Name of .json from YoutubeBundle single account')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Execute one step of migration')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')
            ->setHelp(
                <<<'EOT'

                Command to migrate schema from YoutubeBundle with single account to YoutubeBundle with multiple account.

                - Check before migration (no FORCE parameter):

                The command will check all necessary parameters to execute the migration.
                
                - Check YOUTUBE_TAG_CODE tag exists
                - Check Tag account exists
                - Check .json file for account exists

                - Execute migration (with FORCE parameter):
                
                This command will execute the migration if check was successful.

                1. Migrate Youtube tag publication channel
                2. Migrate Youtube tag playlist
                3. Migrate Youtube documents adding account
                4. Move all playlist tags under Account tag
                5. Update multimedia objects embedding tags
                6. Resync account and playlist EmbeddedTag path/level in all multimedia objects

                Example to check account:

                php app/console youtube:migration:schema --account=my_name_account

                Example to execute migration:

                php app/console youtube:migration:schema --account=my_name_account --step=1 --force
                
                Example to execute all steps migration:

                php app/console youtube:migration:schema --account=my_name_account --step=99 --force
EOT
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->accountName = $input->getOption('account');
        $this->step = (int) $input->getOption('step');
        $this->force = (true === $input->getOption('force'));
        $this->output = $output;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->force) {
            return (int) $this->check();
        }

        $this->output->writeln('<info>***** MIGRATE SCHEMA *****</info>');

        if (!$this->check()) {
            $this->output->writeln('<error>Check before migration failed</error>');

            return 1;
        }

        $this->initializeResults();

        if (1 === $this->step || 99 === $this->step) {
            $this->output->writeln('<info>1. Migrate Youtube tag publication channel</info>');
            $this->migratePubChannelYoutube();
        }

        if (2 === $this->step || 99 === $this->step) {
            $this->output->writeln('<info>2. Migrate Youtube tag playlist</info>');
            $this->migrateYoutubeTag();
        }

        if (3 === $this->step || 99 === $this->step) {
            $output->writeln('<info>3. Migrate Youtube documents adding account</info>');
            $result = $this->migrateYoutubeDocuments();
            if (!$result) {
                $this->createTable();

                return 1;
            }
        }

        if (4 === $this->step || 99 === $this->step) {
            $output->writeln('<info>4. Move all playlist tags under Account tag</info>');
            $result = $this->moveAllPlaylistTags();
            if (!$result) {
                $this->createTable();

                return 1;
            }
        }

        if (5 === $this->step || 99 === $this->step) {
            $output->writeln('<info>5. Update multimedia objects with account tag</info>');
            $this->updateMultimediaObjectsWithAccountTag();
        }

        if (6 === $this->step || 99 === $this->step) {
            $output->writeln('<info>6. Resync account and playlist EmbeddedTag path/level in all multimedia objects</info>');
            $this->resyncPlaylistEmbeddedTags();
        }

        $this->createTable();

        return 0;
    }

    private function check(): bool
    {
        $this->output->writeln('<info>***** CHECK BEFORE MIGRATE *****</info>');

        return $this->accountIsDefinedAndHaveJsonFile();
    }

    private function accountIsDefinedAndHaveJsonFile(): bool
    {
        if (!$this->checkYouTubeTagCode()) {
            $this->output->writeln('<error>Tag YOUTUBE base ('.PumukitYoutubeBundle::YOUTUBE_TAG_CODE.') not found</error>');

            return false;
        }

        if (!$this->checkPubChannelYouTubeTagCode()) {
            $this->output->writeln('<error>Tag YOUTUBE base ('.PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE.') not found</error>');

            return false;
        }

        if (!$this->checkAccountNameTag()) {
            $this->output->writeln('<error>Tag account '.$this->accountName.' not found</error>');

            return false;
        }

        if (!$this->checkJsonFile()) {
            $this->output->writeln('<error>File '.$this->accountStorage.'/'.$this->accountName.'.json not exists</error>');

            return false;
        }

        $this->output->writeln('All checks passed successfully');

        return true;
    }

    private function checkYouTubeTagCode(): ?Tag
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);
        if (!$youtubeTag instanceof Tag) {
            return null;
        }

        return $youtubeTag;
    }

    private function checkPubChannelYouTubeTagCode(): ?Tag
    {
        $pubChannelTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if (!$pubChannelTag instanceof Tag) {
            return null;
        }

        return $pubChannelTag;
    }

    private function checkAccountNameTag(): ?Tag
    {
        $tagAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $this->accountName]);
        if (!$tagAccount instanceof Tag) {
            return null;
        }

        return $tagAccount;
    }

    private function checkJsonFile(): bool
    {
        return file_exists($this->accountStorage.'/'.$this->accountName.'.json');
    }

    private function migratePubChannelYoutube(): bool
    {
        $this->results = array_merge($this->results, [
            'step_1' => "\u{274C}",
        ]);

        $tag = $this->checkPubChannelYouTubeTagCode();

        foreach ($this->pubChannelProperties as $key => $value) {
            $tag->setProperty($key, $value);
        }
        $this->documentManager->flush();

        $this->results = array_merge($this->results, [
            'step_1' => "\u{2705}",
        ]);

        return true;
    }

    private function migrateYoutubeTag(): bool
    {
        $this->results = array_merge($this->results, [
            'step_2' => "\u{274C}",
        ]);

        $tag = $this->checkYouTubeTagCode();

        foreach ($this->youtubeTagProperties as $key => $value) {
            $tag->setProperty($key, $value);
        }

        $this->documentManager->flush();
        $this->documentManager->clear();

        $this->results = array_merge($this->results, [
            'step_2' => "\u{2705}",
        ]);

        return true;
    }

    private function migrateYoutubeDocuments(): bool
    {
        $this->results = array_merge($this->results, [
            'step_3' => "\u{274C}",
        ]);

        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->field('youtubeAccount')->exists(false)
        ;
        $total = (clone $qb)->count()->getQuery()->execute();

        if (0 === $total) {
            $this->output->writeln('YouTube STEP 3: No documents to update');
            $this->results = array_merge($this->results, [
                'step_3' => "\u{26A0}",
            ]);

            return true;
        }

        // Cache the account login as a plain string so the cached value survives clear()
        // (Tag references become detached, but a string is immune).
        $account = $this->checkAccountNameTag();
        $accountLogin = $account->getProperty('login');

        $progress = new ProgressBar($this->output, $total);
        $progress->setFormat('verbose');
        $progress->start();

        $i = 0;
        foreach ($qb->getQuery()->execute() as $youtubeDocument) {
            ++$i;
            $progress->advance();
            $youtubeDocument->setYoutubeAccount($accountLogin);
            if (0 === $i % self::BATCH_SIZE) {
                $this->documentManager->flush();
                $this->documentManager->clear();
            }
        }

        // Final flush for the last partial batch (and for the case where total < BATCH_SIZE).
        $this->documentManager->flush();
        $progress->finish();
        $this->output->writeln('');

        $this->results = array_merge($this->results, [
            'step_3' => "\u{2705}",
        ]);

        return true;
    }

    private function moveAllPlaylistTags(): bool
    {
        $this->results = array_merge($this->results, [
            'step_4' => "\u{274C}",
        ]);

        $youtubeTag = $this->checkYouTubeTagCode();

        $playlistTags = $this->documentManager->getRepository(Tag::class)->findBy(
            [
                'properties.login' => ['$exists' => false],
                'parent.$id' => new ObjectId($youtubeTag->getId()),
            ]
        );

        if (!$playlistTags) {
            $this->output->writeln('Youtube STEP 4: No playlist tags to update');
            $this->results = array_merge($this->results, [
                'step_4' => "\u{26A0}",
            ]);

            return true;
        }

        $tagAccount = $this->checkAccountNameTag();

        // Pass 1: change parent + property on eligible playlist tags (in-memory only)
        $this->output->writeln('Youtube STEP 4: Pass 1/2 — re-parenting playlist tags');
        $progress = new ProgressBar($this->output, count($playlistTags));
        $progress->setFormat('verbose');
        $progress->start();

        $modifiedPlaylists = [];
        foreach ($playlistTags as $playlistTag) {
            $progress->advance();
            if (!$this->refactorPlaylistTag($playlistTag, $tagAccount)) {
                continue;
            }
            $modifiedPlaylists[] = $playlistTag;
        }

        // Persist the parent/property changes so path/level get recomputed by lifecycle callbacks
        // BEFORE updateTag propagates them to EmbeddedTags in MultimediaObjects.
        $this->documentManager->flush();
        $progress->finish();
        $this->output->writeln('');

        if (empty($modifiedPlaylists)) {
            $this->output->writeln('Youtube STEP 4: No playlist tags needed re-parenting');
            $this->results = array_merge($this->results, [
                'step_4' => "\u{2705}",
            ]);

            return true;
        }

        // Pass 2: propagate the new path/level to EmbeddedTags in MultimediaObjects.
        $this->output->writeln(sprintf('Youtube STEP 4: Pass 2/2 — propagating path/level to EmbeddedTags (%d tags)', count($modifiedPlaylists)));
        $progress = new ProgressBar($this->output, count($modifiedPlaylists));
        $progress->setFormat('verbose');
        $progress->start();

        foreach ($modifiedPlaylists as $playlistTag) {
            $progress->advance();
            $this->tagService->updateTag($playlistTag);
        }

        $this->documentManager->flush();
        $progress->finish();
        $this->output->writeln('');

        $this->results = array_merge($this->results, [
            'step_4' => "\u{2705}",
        ]);

        return true;
    }

    private function refactorPlaylistTag(Tag $playlistTag, $tagAccount): bool
    {
        if ($playlistTag->getProperty('login')) {
            return false;
        }

        $playlistTag->setParent($tagAccount);
        $playlistTag->setProperty('youtube_playlist', true);

        return true;
    }

    private function resyncPlaylistEmbeddedTags(): bool
    {
        $this->results = array_merge($this->results, [
            'step_6' => "\u{274C}",
        ]);

        $youtubeTag = $this->checkYouTubeTagCode();
        if (!$youtubeTag) {
            $this->output->writeln('<error>YOUTUBE root tag not found. Run step 1 first.</error>');

            return false;
        }

        $accountTags = $this->documentManager->getRepository(Tag::class)->findBy([
            'parent.$id' => new ObjectId($youtubeTag->getId()),
            'properties.login' => ['$exists' => true],
        ]);

        if (!$accountTags) {
            $this->output->writeln('Youtube STEP 6: No account tags found under YOUTUBE root.');
            $this->results = array_merge($this->results, [
                'step_6' => "\u{26A0}",
            ]);

            return true;
        }

        // Resync both account tags and their playlist children — both can have stale
        // EmbeddedTag path/level in MultimediaObjects after tree reorganizations.
        $tagsToResync = [];
        foreach ($accountTags as $accountTag) {
            $tagsToResync[] = $accountTag;
            $children = $this->documentManager->getRepository(Tag::class)->findBy([
                'parent.$id' => new ObjectId($accountTag->getId()),
            ]);
            foreach ($children as $child) {
                $tagsToResync[] = $child;
            }
        }

        if (empty($tagsToResync)) {
            $this->output->writeln('Youtube STEP 6: No tags to resync.');
            $this->results = array_merge($this->results, [
                'step_6' => "\u{26A0}",
            ]);

            return true;
        }

        $progress = new ProgressBar($this->output, count($tagsToResync));
        $progress->setFormat('verbose');
        $progress->start();

        foreach ($tagsToResync as $tag) {
            $progress->advance();
            $this->tagService->updateTag($tag);
        }

        $this->documentManager->flush();
        $progress->finish();
        $this->output->writeln('');

        $this->results = array_merge($this->results, [
            'step_6' => "\u{2705}",
        ]);

        return true;
    }

    private function updateMultimediaObjectsWithAccountTag(): bool
    {
        $this->results = array_merge($this->results, [
            'step_5' => "\u{274C}",
        ]);

        $puchTag = $this->checkPubChannelYouTubeTagCode();
        if (!$puchTag instanceof Tag) {
            $this->output->writeln('<error>Youtube STEP 5: PUCHYOUTUBE tag not found</error>');

            return false;
        }

        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder();
        $total = (clone $qb)->count()->getQuery()->execute();

        if (0 === $total) {
            $this->output->writeln('Youtube STEP 5: No Youtube documents to process');
            $this->results = array_merge($this->results, [
                'step_5' => "\u{26A0}",
            ]);

            return true;
        }

        $progress = new ProgressBar($this->output, $total);
        $progress->setFormat('verbose');
        $progress->start();

        $stripStatuses = [Youtube::STATUS_REMOVED, Youtube::STATUS_TO_DELETE];
        $updated = 0;
        $skippedStatus = 0;
        $skippedNoAccount = 0;
        $skippedUnresolvable = 0;
        $skippedMmoMissing = 0;
        $iteration = 0;

        foreach ($qb->getQuery()->execute() as $youtubeDocument) {
            // Boundary check at the START of the iteration: flush pending writes from
            // the previous batch, clear the UoW to release memory, and re-resolve the
            // cached PUCHYOUTUBE Tag (it became detached after clear).
            if ($iteration > 0 && 0 === $iteration % self::BATCH_SIZE) {
                $this->documentManager->flush();
                $this->documentManager->clear();
                $puchTag = $this->checkPubChannelYouTubeTagCode();
            }
            ++$iteration;
            $progress->advance();

            if (in_array($youtubeDocument->getStatus(), $stripStatuses, true)) {
                ++$skippedStatus;

                continue;
            }

            $accountLogin = $youtubeDocument->getYoutubeAccount();
            if (!is_string($accountLogin) || '' === $accountLogin) {
                ++$skippedNoAccount;

                continue;
            }

            $accountTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.login' => $accountLogin,
            ]);
            if (!$accountTag instanceof Tag) {
                ++$skippedUnresolvable;

                continue;
            }

            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                '_id' => new ObjectId($youtubeDocument->getMultimediaObjectId()),
            ]);
            if (!$multimediaObject instanceof MultimediaObject) {
                ++$skippedMmoMissing;

                continue;
            }

            $this->ensureAccountAndPlaylistTags($multimediaObject, $accountTag, $youtubeDocument, $puchTag);
            ++$updated;
        }

        // Final flush for the last partial batch (covers iterations since the last clear).
        $this->documentManager->flush();
        $progress->finish();
        $this->output->writeln('');
        $this->output->writeln(sprintf(
            'Youtube STEP 5: updated=%d, skipped(REMOVED/TO_DELETE)=%d, skipped(no account)=%d, skipped(unresolvable account)=%d, skipped(MMO missing)=%d',
            $updated,
            $skippedStatus,
            $skippedNoAccount,
            $skippedUnresolvable,
            $skippedMmoMissing
        ));

        $this->reportOrphanMmos();

        $this->results = array_merge($this->results, [
            'step_5' => "\u{2705}",
        ]);

        return true;
    }

    private function ensureAccountAndPlaylistTags(MultimediaObject $multimediaObject, Tag $accountTag, Youtube $youtubeDocument, Tag $puchTag): void
    {
        if (!$multimediaObject->containsTagWithCod($puchTag->getCod())) {
            $this->tagService->addTag($multimediaObject, $puchTag, false);
        }

        if (!$multimediaObject->containsTagWithCod($accountTag->getCod())) {
            $this->tagService->addTag($multimediaObject, $accountTag, false);
        }

        foreach ($youtubeDocument->getPlaylists() as $playlistCod => $youtubePlaylistId) {
            $playlistTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'cod' => $playlistCod,
                'parent.$id' => new ObjectId($accountTag->getId()),
            ]);
            if (!$playlistTag instanceof Tag) {
                continue;
            }
            if (!$multimediaObject->containsTagWithCod($playlistTag->getCod())) {
                $this->tagService->addTag($multimediaObject, $playlistTag, false);
            }
        }
    }

    private function reportOrphanMmos(): void
    {
        $assignedIds = [];
        $cursor = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->select('multimediaObjectId')
            ->hydrate(false)
            ->getQuery()
            ->execute()
        ;
        foreach ($cursor as $row) {
            if (!empty($row['multimediaObjectId'])) {
                $assignedIds[(string) $row['multimediaObjectId']] = true;
            }
        }

        $candidates = $this->documentManager->getRepository(MultimediaObject::class)->createQueryBuilder()
            ->field('tags.cod')->equals(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE)
            ->getQuery()
            ->execute()
        ;

        $orphans = [];
        foreach ($candidates as $multimediaObject) {
            if (!isset($assignedIds[(string) $multimediaObject->getId()])) {
                $orphans[] = (string) $multimediaObject->getId();
            }
        }

        if (empty($orphans)) {
            $this->output->writeln('Youtube STEP 5: no orphan MultimediaObjects detected');

            return;
        }

        $this->output->writeln(sprintf(
            '<comment>Youtube STEP 5: %d orphan MultimediaObjects detected (PUCHYOUTUBE without Youtube document). Run pumukit:youtube:tags:reconcile --force to clean.</comment>',
            count($orphans)
        ));

        $preview = array_slice($orphans, 0, 10);
        foreach ($preview as $id) {
            $this->output->writeln('  - '.$id);
        }
        if (count($orphans) > 10) {
            $this->output->writeln(sprintf('  ... and %d more', count($orphans) - 10));
        }
    }

    private function initializeResults(): void
    {
        // "\u{2014}" = em dash, displayed for steps not selected by --step or not yet run.
        // Distinct from "\u{274C}" (failed/in-progress), "\u{2705}" (success), "\u{26A0}" (warning).
        $this->results = [
            'step_1' => "\u{2014}",
            'step_2' => "\u{2014}",
            'step_3' => "\u{2014}",
            'step_4' => "\u{2014}",
            'step_5' => "\u{2014}",
            'step_6' => "\u{2014}",
        ];
    }

    private function createTable(): void
    {
        $table = new Table($this->output);
        $table
            ->setHeaders(['Step', 'Result'])
            ->setRows(array_map(fn ($key, $icon) => [$key, $icon], array_keys($this->results), $this->results))
        ;

        $table->render();
    }
}
