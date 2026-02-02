<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Synchronize YoutubeAccount collection to Tags for legacy admin view compatibility
 */
class SyncAccountsToTagsCommand extends Command
{
    protected static $defaultName = 'pumukit:youtube:sync-accounts-to-tags';
    protected static $defaultDescription = 'Sync YouTube accounts from YoutubeAccount collection to Tags (for legacy admin view)';

    private DocumentManager $documentManager;
    private array $pumukitLocales;

    public function __construct(DocumentManager $documentManager, array $pumukitLocales)
    {
        $this->documentManager = $documentManager;
        $this->pumukitLocales = $pumukitLocales;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Synchronizing YouTube Accounts to Tags');

        // 1. Get or create root YOUTUBE tag
        $rootTag = $this->getOrCreateRootTag($io);

        // 2. Get all YoutubeAccount documents
        $accounts = $this->documentManager->getRepository(YoutubeAccount::class)->findAll();
        
        if (empty($accounts)) {
            $io->warning('No YouTube accounts found in YoutubeAccount collection');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Found %d account(s) to sync', count($accounts)));

        $synced = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $accountId = $account->getId();
            $accountName = $account->getAccountName();
            $login = $account->getLogin();

            // Check if tag already exists
            $existingTag = $this->documentManager
                ->getRepository(Tag::class)
                ->findOneBy(['properties.youtube_account_id' => $accountId]);

            if ($existingTag) {
                $io->text(sprintf('  ✓ Account "%s" already synced (Tag ID: %s)', $accountName, $existingTag->getId()));
                $skipped++;
                continue;
            }

            // Create new tag for this account
            $tag = new Tag();
            $tag->setCod($accountId); // Use account ID as code
            $tag->setMetatag(false);
            $tag->setDisplay(true);
            $tag->setParent($rootTag);

            // Set titles in all locales
            foreach ($this->pumukitLocales as $locale) {
                $title = $accountName . ($login ? " ({$login})" : '');
                $tag->setTitle($title, $locale);
            }

            // Store account properties
            $tag->setProperty('youtube_account_id', $accountId);
            $tag->setProperty('login', $login);
            $tag->setProperty('account_name', $accountName);
            $tag->setProperty('hide_in_tag_group', true);

            $this->documentManager->persist($tag);
            $synced++;

            $io->text(sprintf('  ✓ Created tag for account "%s" (Login: %s)', $accountName, $login ?: 'N/A'));
        }

        $this->documentManager->flush();

        $io->success([
            'Synchronization completed!',
            sprintf('Synced: %d accounts', $synced),
            sprintf('Skipped: %d accounts (already synced)', $skipped),
        ]);

        $io->note('You can now view the accounts at: /admin/youtube/admin/youtube/list');

        return Command::SUCCESS;
    }

    private function getOrCreateRootTag(SymfonyStyle $io): Tag
    {
        $rootTag = $this->documentManager
            ->getRepository(Tag::class)
            ->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);

        if ($rootTag) {
            $io->text('  ✓ Root YOUTUBE tag already exists');
            return $rootTag;
        }

        $io->text('  → Creating root YOUTUBE tag...');

        $rootTag = new Tag();
        $rootTag->setCod(PumukitYoutubeBundle::YOUTUBE_TAG_CODE);
        $rootTag->setMetatag(true);
        $rootTag->setDisplay(true);

        foreach ($this->pumukitLocales as $locale) {
            $rootTag->setTitle('YouTube Accounts', $locale);
        }

        $rootTag->setProperty('hide_in_tag_group', true);

        $this->documentManager->persist($rootTag);
        $this->documentManager->flush();

        $io->text('  ✓ Root YOUTUBE tag created');

        return $rootTag;
    }
}
