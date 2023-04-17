<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class MigrationCommand extends Command
{
    private $documentManager;
    private $pubChannelProperties = [
        'modal_path' => 'pumukityoutube_modal_index',
        'advanced_configuration' => 'pumukityoutube_advance_configuration_index',
    ];

    private $locales;
    private $accountName;
    private $youtubeTagProperties = [
        'hide_in_tag_group' => true,
    ];

    private $force;
    private $pumukitLocales;

    public function __construct(DocumentManager $documentManager, array $pumukitLocales)
    {
        $this->documentManager = $documentManager;
        $this->pumukitLocales = $pumukitLocales;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:migration:schema')
            ->setDescription('Migrate schema from PumukitYoutubeBundle single account to PumukitYoutubeBundle multiple account')
            ->addOption('single_account_name', null, InputOption::VALUE_REQUIRED, 'Name of .json from YoutubeBundle single account')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')
            ->setHelp(
                <<<'EOT'

                Command to migrate schema from YoutubeBundle with single account to YoutubeBundle with multiple account.

                Steps without force option:

                1. Check if account file ( json ) exists.
                2. Check if tags with login property exists.

                Steps with force option:

                1. Migrate Youtube account
                2. Migrate Youtube tag publication channel
                3. Migrate Youtube tag playlist
                4. Migrate Youtube documents adding account
                5. Move all playlist tags under Account tag
                6. Update multimedia objects embedding tags

                Example to check account:

                php app/console youtube:migration:schema --single_account_name=my_name_account

                Example to execute migration:

                php app/console youtube:migration:schema --single_account_name=my_name_account --force
EOT
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->accountName = $input->getOption('single_account_name');
        $this->force = (true === $input->getOption('force'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->force) {
            $output->writeln(
                [
                    "\n",
                    '<info>1. Check if account file ( json ) exists.</info>',
                ]
            );

            $fileExists = $this->findJsonAccount();
            if (!$fileExists) {
                $output->writeln('<error>File '.$this->accountName.'.json not exists</error>');
            } else {
                $output->writeln('File '.$this->accountName.'.json exists');
            }

            $output->writeln(
                [
                    "\n",
                    '<info>2. Check if tags with login property exists</info>',
                ]
            );
            $this->checkAccountExists($output);

            return true;
        }

        $output->writeln(
            [
                "\n",
                '<info>1. Migrate Youtube account</info>',
            ]
        );
        $this->migrateYoutubeAccount($output);

        $output->writeln(
            [
                "\n",
                '<info>2. Migrate Youtube tag publication channel</info>',
            ]
        );
        $this->migratePubChannelYoutube($output);

        $output->writeln(
            [
                "\n",
                '<info>3. Migrate Youtube tag playlist</info>',
            ]
        );
        $this->migrateYoutubeTag($output);

        $output->writeln(
            [
                "\n",
                '<info>4. Migrate Youtube documents adding account</info>',
            ]
        );
        $this->migrateYoutubeDocuments($output);

        $output->writeln(
            [
                "\n",
                '<info>5. Move all playlist tags under Account tag</info>',
            ]
        );
        $this->moveAllPlaylistTags($output);

        $output->writeln(
            [
                "\n",
                '<info>6. Update multimedia objects with account tag</info>',
            ]
        );
        $this->updateMultimediaObjectsWithAccountTag($output);

        return true;
    }

    private function migratePubChannelYoutube(OutputInterface $output): void
    {
        $puchYoutube = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE]);

        if (!$puchYoutube) {
            throw new \Exception(' ERROR - '.PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE." doesn't exists");
        }

        foreach ($this->pubChannelProperties as $key => $value) {
            $puchYoutube->setProperty($key, $value);
        }

        $this->documentManager->flush();

        $output->writeln('Youtube - SKIP - Added properties to '.PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE);
    }

    private function migrateYoutubeAccount(OutputInterface $output): void
    {
        $existsFile = $this->findJsonAccount();
        if (!$existsFile) {
            throw new \Exception($this->accountName.' file doesnt exists');
        }

        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        if (!$youtubeTag) {
            throw new \Exception('Youtube - ERROR - '.PumukitYoutubeBundle::YOUTUBE_TAG_CODE." doesn't exists");
        }

        $this->createYoutubeTagAccount($output, $youtubeTag);

        $output->writeln('Youtube - SKIP - Created Youtube tag account with name: '.$this->accountName);
    }

    private function createYoutubeTagAccount(OutputInterface $output, Tag $youtubeTag): void
    {
        $tagAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $this->accountName]);

        if ($tagAccount) {
            $output->writeln('Tag account with login '.$this->accountName.' exists on BBDD');
        }

        $tagYoutubeAccount = new Tag();

        foreach ($this->locales as $locale) {
            $tagYoutubeAccount->setTitle($this->accountName, $locale);
        }

        $tagYoutubeAccount->setProperty('login', $this->accountName);

        $tagYoutubeAccount->setParent($youtubeTag);

        $this->documentManager->persist($tagYoutubeAccount);
        $this->documentManager->flush();

        $tagYoutubeAccount->setCod($tagYoutubeAccount->getId());

        $this->documentManager->flush();
    }

    private function migrateYoutubeTag(OutputInterface $output): void
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]);

        foreach ($this->youtubeTagProperties as $key => $value) {
            $youtubeTag->setProperty($key, $value);
        }

        $this->documentManager->flush();

        $output->writeln('Youtube - SKIP - Added properties to '.PumukitYoutubeBundle::YOUTUBE_TAG_CODE);

        $this->documentManager->clear();
    }

    private function migrateYoutubeDocuments(OutputInterface $output): bool
    {
        $youtubeDocuments = $this->documentManager->getRepository(Youtube::class)->findBy(['youtubeAccount' => ['$exists' => false]]);

        if (!$youtubeDocuments) {
            $output->writeln('Youtube - SKIP - No documents to update');

            return false;
        }

        $progress = new ProgressBar($output, count($youtubeDocuments));
        $progress->setFormat('verbose');

        $progress->start();

        $tagAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $this->accountName]);
        if (!$tagAccount) {
            throw new \Exception('Youtube - ERROR - '.$this->accountName." tag doesn't exists");
        }

        $i = 0;
        foreach ($youtubeDocuments as $youtubeDocument) {
            ++$i;
            $progress->advance();
            $this->addYoutubeAccount($youtubeDocument, $tagAccount);
            if (0 === $i % 50) {
                $this->documentManager->flush();
            }
        }

        $this->documentManager->flush();
        $progress->finish();

        return true;
    }

    private function addYoutubeAccount(Youtube $youtube, Tag $tagAccount): void
    {
        $youtube->setYoutubeAccount($tagAccount->getProperty('login'));
    }

    private function moveAllPlaylistTags(OutputInterface $output): void
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(
            ['cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE]
        );

        $playlistTags = $this->documentManager->getRepository(Tag::class)->findBy(
            [
                'properties.login' => ['$exists' => false],
                'parent.$id' => new ObjectId($youtubeTag->getId()),
            ]
        );

        if (!$playlistTags) {
            throw new \Exception('Youtube - ERROR - playlist tags not found. Did execute the script before ?');
        }

        $progress = new ProgressBar($output, count($playlistTags));
        $progress->setFormat('verbose');

        $progress->start();

        $tagAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $this->accountName]);

        if (!$tagAccount) {
            throw new \Exception('Youtube - ERROR - '.$this->accountName." tag doesn't exists");
        }

        foreach ($playlistTags as $playlistTag) {
            $progress->advance();
            $this->refactorPlaylistTag($playlistTag, $tagAccount);
        }

        $this->documentManager->flush();
        $progress->finish();
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

    private function findJsonAccount(): bool
    {
        $finder = new Finder();
        $files = $finder->files()->in(__DIR__.'/../Resources/data/accounts');
        foreach ($files as $file) {
            if ($file->getFileName() === $this->accountName.'.json') {
                return true;
            }
        }

        return false;
    }

    private function updateMultimediaObjectsWithAccountTag(OutputInterface $output): bool
    {
        $multimediaObjects = $this->documentManager->getRepository(MultimediaObject::class)->findBy(
            [
                'tags.cod' => [
                    '$all' => [
                        PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE,
                        PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
                    ],
                ],
            ]
        );

        if (!$multimediaObjects) {
            $output->writeln('Youtube - SKIP - No multimedia objects to add tag account');

            return false;
        }

        $tagAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $this->accountName]);

        $progress = new ProgressBar($output, count($multimediaObjects));
        $progress->setFormat('verbose');

        $progress->start();

        $i = 0;
        foreach ($multimediaObjects as $multimediaObject) {
            ++$i;
            $progress->advance();
            $this->addTagAccountOnMultimediaObject($multimediaObject, $tagAccount);
            if (0 === $i % 50) {
                $this->documentManager->flush();
            }
        }

        $this->documentManager->flush();
        $progress->finish();

        return true;
    }

    private function addTagAccountOnMultimediaObject(MultimediaObject $multimediaObject, Tag $tagAccount): void
    {
        $multimediaObject->addTag($tagAccount);
    }

    private function checkAccountExists(OutputInterface $output): void
    {
        $tagAccount = $this->documentManager->getRepository(Tag::class)->findBy(
            [
                'properties.login' => $this->accountName,
            ]
        );

        if ($tagAccount) {
            $output->writeln('<error>There are accounts defined on BBDD'.count($tagAccount).'</error>');
        } else {
            $output->writeln('There arent accounts defined on BBDD');
        }
    }
}
