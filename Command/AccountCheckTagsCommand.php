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

/**
 * Detects (and optionally repairs) MultimediaObjects whose embedded YouTube
 * account tag is inconsistent with the account stored in the Youtube document.
 *
 * Usage:
 *   php bin/console pumukit:youtube:account:check:tags
 *   php bin/console pumukit:youtube:account:check:tags --fix
 *   php bin/console pumukit:youtube:account:check:tags --account=myaccount
 */
class AccountCheckTagsCommand extends Command
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
            ->setName('pumukit:youtube:account:check:tags')
            ->setDescription('Detect (and optionally repair) MultimediaObjects with inconsistent YouTube account tags')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Repair the inconsistent tags automatically')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Limit the check to a specific account login')
            ->setHelp(
                <<<'EOT'
This command detects MultimediaObjects where the YouTube account tag embedded in
the object does not match (or is missing) the account stored in the Youtube document.

This inconsistency causes errors like:
  GoogleAccountService::createClientWithAccessToken(): Argument #1 ($login) must be
  of type string, null given

Run without --fix to get a report only:
  php bin/console pumukit:youtube:account:check:tags

Run with --fix to automatically add the correct account tag to the MultimediaObject:
  php bin/console pumukit:youtube:account:check:tags --fix

Filter by account:
  php bin/console pumukit:youtube:account:check:tags --account=myaccount

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fix = $input->getOption('fix');
        $filterAccount = $input->getOption('account');

        $io->title('YouTube Account Tag Consistency Check');
        if ($fix) {
            $io->warning('--fix mode enabled: inconsistent tags will be repaired.');
        }

        // Load the YOUTUBE root tag
        $youtubeRootTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        if (!$youtubeRootTag) {
            $io->error('YOUTUBE root tag not found. Run pumukit:youtube:init:pubchannel first.');

            return Command::FAILURE;
        }

        // Build the query for Youtube documents
        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->field('youtubeAccount')->exists(true)
            ->field('youtubeAccount')->notEqual(null)
            ->field('status')->notIn([Youtube::STATUS_REMOVED])
        ;

        if ($filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }

        $youtubeDocuments = $qb->getQuery()->execute();

        $totalChecked = 0;
        $totalInconsistent = 0;
        $totalFixed = 0;
        $rows = [];

        foreach ($youtubeDocuments as $youtubeDocument) {
            /** @var Youtube $youtubeDocument */
            ++$totalChecked;

            $accountLogin = $youtubeDocument->getYoutubeAccount();

            // Find the authoritative account Tag by login
            $accountTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.login' => $accountLogin,
            ]);

            if (!$accountTag) {
                $rows[] = [
                    $youtubeDocument->getMultimediaObjectId(),
                    $youtubeDocument->getId(),
                    $accountLogin,
                    '<error>Account Tag not found in DB</error>',
                    $fix ? '—' : '—',
                ];
                ++$totalInconsistent;

                continue;
            }

            // Find the MultimediaObject
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                '_id' => new ObjectId($youtubeDocument->getMultimediaObjectId()),
            ]);

            if (!$multimediaObject) {
                // Orphan Youtube document — not a tag inconsistency per se
                continue;
            }

            // Check what validateMultimediaObjectAccount would return via tags
            $tagBasedAccount = $this->findAccountViaEmbeddedTags($multimediaObject, $youtubeRootTag);

            $inconsistency = $this->detectInconsistency($accountTag, $tagBasedAccount, $accountLogin);

            if (null === $inconsistency) {
                // All good
                continue;
            }

            ++$totalInconsistent;

            $fixResult = '—';
            if ($fix) {
                $fixResult = $this->repairTags($multimediaObject, $accountTag, $youtubeRootTag)
                    ? '<info>Fixed</info>'
                    : '<error>Fix failed</error>';
                if ('<info>Fixed</info>' === $fixResult) {
                    ++$totalFixed;
                }
            }

            $rows[] = [
                $multimediaObject->getId(),
                $youtubeDocument->getId(),
                $accountLogin,
                $inconsistency,
                $fixResult,
            ];
        }

        if (empty($rows)) {
            $io->success(sprintf('Checked %d Youtube documents. No inconsistencies found.', $totalChecked));

            return Command::SUCCESS;
        }

        $io->table(
            ['MultimediaObject ID', 'Youtube Doc ID', 'Expected account', 'Problem', 'Fix'],
            $rows
        );

        $io->writeln(sprintf(
            '<comment>Checked: %d | Inconsistent: %d%s</comment>',
            $totalChecked,
            $totalInconsistent,
            $fix ? sprintf(' | Fixed: %d', $totalFixed) : ' (run with --fix to repair)'
        ));

        return $totalInconsistent > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Replicates the logic of CommonDataValidationService::validateMultimediaObjectAccount()
     * to find the account via embedded tags (the buggy path).
     */
    private function findAccountViaEmbeddedTags(MultimediaObject $multimediaObject, Tag $youtubeRootTag): ?Tag
    {
        foreach ($multimediaObject->getTags() as $embeddedTag) {
            if ($embeddedTag->isChildOf($youtubeRootTag)) {
                return $this->documentManager->getRepository(Tag::class)->findOneBy([
                    'cod' => $embeddedTag->getCod(),
                ]);
            }
        }

        return null;
    }

    /**
     * Returns a human-readable description of the inconsistency, or null if everything is OK.
     */
    private function detectInconsistency(Tag $expectedAccount, ?Tag $tagBasedAccount, string $accountLogin): ?string
    {
        if (null === $tagBasedAccount) {
            return 'No YouTube child tag embedded in MultimediaObject';
        }

        $tagLogin = $tagBasedAccount->getProperty('login');

        if (!is_string($tagLogin) || '' === $tagLogin) {
            return sprintf(
                'Embedded tag (cod: %s) has no "login" property → would cause TypeError',
                $tagBasedAccount->getCod()
            );
        }

        if ($tagLogin !== $accountLogin) {
            return sprintf(
                'Embedded tag points to account "%s" but Youtube document says "%s"',
                $tagLogin,
                $accountLogin
            );
        }

        return null;
    }

    /**
     * Repairs the MultimediaObject by ensuring it has the correct account tag embedded
     * and removing any other direct-child-of-YOUTUBE tags that do not match.
     */
    private function repairTags(MultimediaObject $multimediaObject, Tag $correctAccountTag, Tag $youtubeRootTag): bool
    {
        try {
            // Remove incorrect YouTube account tags (direct children of YOUTUBE that are not the correct one)
            foreach ($multimediaObject->getTags() as $embeddedTag) {
                if ($embeddedTag->isChildOf($youtubeRootTag) && $embeddedTag->getCod() !== $correctAccountTag->getCod()) {
                    $wrongTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                        'cod' => $embeddedTag->getCod(),
                    ]);
                    if ($wrongTag) {
                        $this->tagService->removeTagFromMultimediaObject($multimediaObject, $wrongTag->getId());
                    }
                }
            }

            // Add the correct account tag if not already present
            if (!$multimediaObject->containsTagWithCod($correctAccountTag->getCod())) {
                $this->tagService->addTagToMultimediaObject($multimediaObject, $correctAccountTag->getId());
            }

            $this->documentManager->flush();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

