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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AccountSyncTagsCommand extends Command
{
    private DocumentManager $documentManager;
    private TagService $tagService;

    public function __construct(DocumentManager $documentManager, TagService $tagService)
    {
        $this->documentManager = $documentManager;
        $this->tagService = $tagService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:account:sync:tags')
            ->setDescription('Sync MultimediaObject embedded YouTube tags using the Youtube document as source of truth')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Limit the sync to a specific account login')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply changes (otherwise runs in dry-run mode)')
            ->setHelp(
                <<<'EOT'
For each Youtube document, this command reconciles the embedded tags in the
linked MultimediaObject:

- If status is REMOVED or TO_DELETE:
    The MultimediaObject must NOT carry the YouTube publication channel tag
    (PUCHYOUTUBE), the account tag, nor any playlist tag (any descendant of
    the YOUTUBE root tag). All of those are stripped.

- Otherwise:
    The account tag (resolved by Youtube document's youtubeAccount login)
    and every playlist tag listed in the Youtube document's playlists map
    are added to the MultimediaObject if missing.

Dry-run by default. Add --force to apply changes.

  php bin/console pumukit:youtube:account:sync:tags
  php bin/console pumukit:youtube:account:sync:tags --account=myaccount
  php bin/console pumukit:youtube:account:sync:tags --force
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filterAccount = $input->getOption('account');
        $apply = (bool) $input->getOption('force');

        $io->title('YouTube Account Tag Sync'.($apply ? '' : ' (dry-run)'));

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

        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder();
        if ($filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }
        $youtubeDocuments = $qb->getQuery()->execute();

        $stripStatuses = [Youtube::STATUS_REMOVED, Youtube::STATUS_TO_DELETE];

        $totals = ['checked' => 0, 'added' => 0, 'removed' => 0, 'skipped' => 0, 'mmo_missing' => 0];
        $rows = [];

        foreach ($youtubeDocuments as $youtubeDocument) {
            // @var Youtube $youtubeDocument
            ++$totals['checked'];

            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                '_id' => new ObjectId($youtubeDocument->getMultimediaObjectId()),
            ]);

            if (!$multimediaObject) {
                ++$totals['mmo_missing'];
                $rows[] = [
                    $youtubeDocument->getMultimediaObjectId(),
                    $youtubeDocument->getId(),
                    (string) $youtubeDocument->getStatus(),
                    '-',
                    'MMO not found — skip',
                ];

                continue;
            }

            if (in_array($youtubeDocument->getStatus(), $stripStatuses, true)) {
                $actions = $this->stripYoutubeTags($multimediaObject, $youtubeRootTag, $puchYoutubeTag, $apply);
                if (!$actions) {
                    ++$totals['skipped'];

                    continue;
                }
                $totals['removed'] += count($actions);
                $rows[] = [
                    $multimediaObject->getId(),
                    $youtubeDocument->getId(),
                    (string) $youtubeDocument->getStatus(),
                    'strip',
                    implode(', ', $actions),
                ];

                continue;
            }

            $actions = $this->ensureYoutubeTags($multimediaObject, $youtubeDocument, $apply);
            if (null === $actions) {
                ++$totals['skipped'];
                $rows[] = [
                    $multimediaObject->getId(),
                    $youtubeDocument->getId(),
                    (string) $youtubeDocument->getStatus(),
                    '-',
                    sprintf('Account tag not resolvable for login "%s"', (string) $youtubeDocument->getYoutubeAccount()),
                ];

                continue;
            }

            if (empty($actions)) {
                ++$totals['skipped'];

                continue;
            }
            $totals['added'] += count($actions);
            $rows[] = [
                $multimediaObject->getId(),
                $youtubeDocument->getId(),
                (string) $youtubeDocument->getStatus(),
                'add',
                implode(', ', $actions),
            ];
        }

        if (!empty($rows)) {
            $io->table(['MMO ID', 'Youtube Doc ID', 'Doc status', 'Action', 'Tags'], $rows);
        }

        $io->section('Summary');
        $io->writeln(sprintf(
            'Checked: %d | Added: %d | Removed: %d | No-op/skip: %d | MMO missing: %d',
            $totals['checked'],
            $totals['added'],
            $totals['removed'],
            $totals['skipped'],
            $totals['mmo_missing']
        ));

        if (!$apply) {
            $io->note('Dry-run mode. Re-run with --force to apply the changes above.');
        } else {
            $io->success('Sync applied.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return string[] List of removed tag descriptions (cod). Empty if nothing to do.
     */
    private function stripYoutubeTags(MultimediaObject $multimediaObject, Tag $youtubeRootTag, Tag $puchYoutubeTag, bool $apply): array
    {
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
            foreach ($tagsToRemove as $tag) {
                $this->tagService->removeOneTag($multimediaObject, $tag, false);
            }
            $this->documentManager->flush();
        }

        return array_keys($tagsToRemove);
    }

    /**
     * @return string[]|null Added tag descriptions, or null if the account tag cannot be resolved.
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

        $added = [];

        if (!$multimediaObject->containsTag($accountTag)) {
            if ($apply) {
                $this->tagService->addTag($multimediaObject, $accountTag, false);
            }
            $added[] = $accountTag->getCod();
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
            if ($apply) {
                $this->tagService->addTag($multimediaObject, $playlistTag, false);
            }
            $added[] = $playlistTag->getCod();
        }

        if ($apply && !empty($added)) {
            $this->documentManager->flush();
        }

        return $added;
    }
}
