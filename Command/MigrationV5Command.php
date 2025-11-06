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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationV5Command extends Command
{
    private $documentManager;
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

    public function __construct(DocumentManager $documentManager, string $accountStorage)
    {
        $this->documentManager = $documentManager;
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
            $output->writeln('<info>6. Update multimedia objects with account tag</info>');
            $this->updateMultimediaObjectsWithAccountTag();
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
        $this->results = [
            'step_1' => "\u{274C}",
        ];

        $tag = $this->checkPubChannelYouTubeTagCode();

        foreach ($this->pubChannelProperties as $key => $value) {
            $tag->setProperty($key, $value);
        }
        $this->documentManager->flush();

        $this->results = [
            'step_1' => "\u{2705}",
        ];

        return true;
    }

    private function migrateYoutubeTag(): bool
    {
        $this->results = [
            'step_2' => "\u{274C}",
        ];

        $tag = $this->checkYouTubeTagCode();

        foreach ($this->youtubeTagProperties as $key => $value) {
            $tag->setProperty($key, $value);
        }

        $this->documentManager->flush();
        $this->documentManager->clear();

        $this->results = [
            'step_2' => "\u{2705}",
        ];

        return true;
    }

    private function migrateYoutubeDocuments(): bool
    {
        $this->results = [
            'step_3' => "\u{274C}",
        ];

        $youtubeDocuments = $this->documentManager->getRepository(Youtube::class)->findBy(['youtubeAccount' => ['$exists' => false]]);
        if (!$youtubeDocuments) {
            $this->output->writeln('YouTube STEP 3: No documents to update');
            $this->results = [
                'step_3' => "\u{26A0}",
            ];

            return true;
        }

        $progress = new ProgressBar($this->output, count($youtubeDocuments));
        $progress->setFormat('verbose');

        $progress->start();

        $account = $this->checkAccountNameTag();

        $i = 0;
        foreach ($youtubeDocuments as $youtubeDocument) {
            ++$i;
            $progress->advance();
            $this->addYoutubeAccount($youtubeDocument, $account);
            if (0 === $i % 50) {
                $this->documentManager->flush();
            }
        }

        $this->documentManager->flush();
        $progress->finish();

        $this->results = [
            'step_3' => "\u{2705}",
        ];

        return true;
    }

    private function addYoutubeAccount(Youtube $youtube, Tag $tagAccount): void
    {
        $youtube->setYoutubeAccount($tagAccount->getProperty('login'));
    }

    private function moveAllPlaylistTags(): bool
    {
        $this->results = [
            'step_4' => "\u{274C}",
        ];

        $youtubeTag = $this->checkYouTubeTagCode();

        $playlistTags = $this->documentManager->getRepository(Tag::class)->findBy(
            [
                'properties.login' => ['$exists' => false],
                'parent.$id' => new ObjectId($youtubeTag->getId()),
            ]
        );

        if (!$playlistTags) {
            $this->output->writeln('Youtube STEP 4: No playlist tags to update');
            $this->results = [
                'step_4' => "\u{26A0}",
            ];

            return true;
        }

        $progress = new ProgressBar($this->output, count($playlistTags));
        $progress->setFormat('verbose');

        $progress->start();

        $tagAccount = $this->checkAccountNameTag();
        foreach ($playlistTags as $playlistTag) {
            $progress->advance();
            $this->refactorPlaylistTag($playlistTag, $tagAccount);
        }

        $this->documentManager->flush();
        $progress->finish();

        $this->results = [
            'step_4' => "\u{2705}",
        ];

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

    private function updateMultimediaObjectsWithAccountTag(): bool
    {
        $this->results = [
            'step_5' => "\u{274C}",
        ];

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
            $this->output->writeln('Youtube STEP 5: No multimedia objects to add tag account');
            $this->results = [
                'step_5' => "\u{26A0}",
            ];

            return false;
        }

        $tagAccount = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $this->accountName]);

        $progress = new ProgressBar($this->output, count($multimediaObjects));
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

        $this->results = [
            'step_5' => "\u{2705}",
        ];

        return true;
    }

    private function addTagAccountOnMultimediaObject(MultimediaObject $multimediaObject, Tag $tagAccount): void
    {
        $multimediaObject->addTag($tagAccount);
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
