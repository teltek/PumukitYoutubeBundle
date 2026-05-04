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

class AccountCheckTagsCommand extends Command
{
    private DocumentManager $documentManager;
    private YoutubeConfigurationService $youtubeConfigurationService;

    public function __construct(DocumentManager $documentManager, YoutubeConfigurationService $youtubeConfigurationService)
    {
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:account:check:tags')
            ->setDescription('Detect MultimediaObjects with inconsistent YouTube account tags')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Limit the check to a specific account login')
            ->setHelp(
                <<<'EOT'
This command detects MultimediaObjects where the YouTube account tag embedded in
the object does not match (or is missing) the account stored in the Youtube document.

This inconsistency causes errors like:
  GoogleAccountService::createClientWithAccessToken(): Argument #1 ($login) must be
  of type string, null given

Documents with status REMOVED are also checked: if uploadRemovedVideos is enabled,
they will be re-uploaded and the same inconsistency will cause the same errors.

Run to get a full report:
  php bin/console pumukit:youtube:account:check:tags

Filter by account:
  php bin/console pumukit:youtube:account:check:tags --account=myaccount

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filterAccount = $input->getOption('account');

        $io->title('YouTube Account Tag Consistency Check');

        $this->renderConfiguration($io);

        $youtubeRootTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        if (!$youtubeRootTag) {
            $io->error('YOUTUBE root tag not found. Run pumukit:youtube:init:pubchannel first.');

            return Command::FAILURE;
        }

        $qb = $this->documentManager->getRepository(Youtube::class)->createQueryBuilder()
            ->field('youtubeAccount')->exists(true)
            ->field('youtubeAccount')->notEqual(null)
        ;

        if ($filterAccount) {
            $qb->field('youtubeAccount')->equals($filterAccount);
        }

        $youtubeDocuments = $qb->getQuery()->execute();

        $totalChecked = 0;
        $totalInconsistent = 0;
        $rows = [];

        $statusLabels = [
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

        foreach ($youtubeDocuments as $youtubeDocument) {
            /** @var Youtube $youtubeDocument */
            ++$totalChecked;

            $accountLogin = $youtubeDocument->getYoutubeAccount();
            $docStatus = $statusLabels[$youtubeDocument->getStatus()] ?? (string) $youtubeDocument->getStatus();

            $accountTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.login' => $accountLogin,
            ]);

            if (!$accountTag) {
                $rows[] = [
                    $youtubeDocument->getMultimediaObjectId(),
                    $youtubeDocument->getId(),
                    $accountLogin,
                    $docStatus,
                    '<error>Account Tag not found in DB</error>',
                ];
                ++$totalInconsistent;

                continue;
            }

            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)->findOneBy([
                '_id' => new ObjectId($youtubeDocument->getMultimediaObjectId()),
            ]);

            if (!$multimediaObject) {
                continue;
            }

            $tagBasedAccount = $this->findAccountViaEmbeddedTags($multimediaObject, $youtubeRootTag);
            $inconsistency = $this->detectInconsistency($tagBasedAccount, $accountLogin);

            if (null === $inconsistency) {
                continue;
            }

            ++$totalInconsistent;
            $rows[] = [
                $multimediaObject->getId(),
                $youtubeDocument->getId(),
                $accountLogin,
                $docStatus,
                $inconsistency,
            ];
        }

        $io->section('Results');

        if (empty($rows)) {
            $io->success(sprintf('Checked %d Youtube documents. No inconsistencies found.', $totalChecked));

            return Command::SUCCESS;
        }

        $io->table(
            ['MultimediaObject ID', 'Youtube Doc ID', 'Expected account', 'Doc status', 'Problem'],
            $rows
        );

        $io->writeln(sprintf(
            '<comment>Checked: %d | Inconsistent: %d</comment>',
            $totalChecked,
            $totalInconsistent
        ));

        return Command::FAILURE;
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
                $value = $value ? '<info>true</info>' : '<comment>false</comment>';
            }
            $rows[] = [$key, (string) $value];
        }

        $io->table(['Parameter', 'Value'], $rows);
    }

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

    private function detectInconsistency(?Tag $tagBasedAccount, string $accountLogin): ?string
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
}
