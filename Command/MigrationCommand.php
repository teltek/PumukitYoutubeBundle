<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Finder\Finder;

/**
 * Class MigrationCommand.
 */
class MigrationCommand extends ContainerAwareCommand
{
    /**
     * @var DocumentManager
     */
    private $dm;

    private $puchYoutubeCod = 'PUCHYOUTUBE';

    /**
     * Information taken from YoutubeInitTagsCommand.
     *
     * @var array
     */
    private $pubChannelProperties = [
        'modal_path' => 'pumukityoutube_modal_index',
        'advanced_configuration' => 'pumukityoutube_advance_configuration_index',
    ];

    private $locales;

    private $accountName;

    private $tagYoutubeCod = 'YOUTUBE';

    private $youtubeTagProperties = [
        'hide_in_tag_group' => true,
    ];

    protected function configure()
    {
        $this
            ->setName('youtube:migration:schema')
            ->setDescription('Migrate schema from PumukitYoutubeBundle single account to PumukitYoutubeBundle multiple account')
            ->addOption('single_account_name', null, InputOption::VALUE_REQUIRED, 'Name of .json from YoutubeBundle single account')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')
            ->setHelp(
                <<<'EOT'
                
                Command to migrate schema from bundle with single account to bundle with multiple account.
                
                Steps: 
                
                1. Migrate Youtube tag publication channel
                2. Migrate Youtube account
                3. Migrate Youtube tag playlist
                4. Migrate Youtube documents adding account
                5. Move all playlist tags under Account tag
                6. Update multimedia objects embedding tags
                
                Example:
                
                php app/console youtube:migration:schema --single_account_name=my_name_account --force
EOT
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $this->locales = $this->getContainer()->getParameter('pumukit2.locales');

        $this->accountName = $input->getOption('single_account_name');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // NOTE: Migrate Youtube tag publication channel
        $this->migratePubChannelYoutube($output);

        // NOTE: Migrate Youtube account
        $this->migrateYoutubeAccount($output);

        // NOTE: Migrate Youtube tag playlist
        $this->migrateYoutubeTag($output);

        // NOTE: Migrate Youtube documents adding account
        $this->migrateYoutubeDocuments($output);

        // NOTE: Move all playlist tags under Account tag
        $this->moveAllPlaylistTags($output);
    }

    /**
     * @param OutputInterface $output
     *
     * @throws \Exception
     */
    private function migratePubChannelYoutube(OutputInterface $output)
    {
        $puchYoutube = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
            ['cod' => $this->puchYoutubeCod]
        );

        if (!$puchYoutube) {
            throw new \Exception(' ERROR - '.$this->puchYoutubeCod." doesn't exists");
        }

        foreach ($this->pubChannelProperties as $key => $value) {
            $puchYoutube->setProperty($key, $value);
        }

        $this->dm->flush();

        $output->writeln('***** Youtube - SKIP - Added properties to '.$this->puchYoutubeCod);
    }

    /**
     * @param OutputInterface $output
     *
     * @throws \Exception
     */
    private function migrateYoutubeAccount(OutputInterface $output)
    {
        $existsFile = $this->findJsonAccount();
        if (!$existsFile) {
            throw new \Exception($this->accountName.' file doesnt exists');
        }

        $youtubeTag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
            ['cod' => $this->tagYoutubeCod]
        );

        if (!$youtubeTag) {
            throw new \Exception(' Youtube - ERROR - '.$this->tagYoutubeCod." doesn't exists");
        }

        $this->createYoutubeTagAccount($output, $youtubeTag);

        $output->writeln(' Youtube - SKIP - Created Youtube tag account with name: '.$this->accountName);
    }

    /**
     * @param OutputInterface $output
     * @param Tag             $youtubeTag
     */
    private function createYoutubeTagAccount(OutputInterface $output, Tag $youtubeTag)
    {
        $tagAccount = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
            ['properties.login' => $this->accountName]
        );

        if ($tagAccount) {
            $output->writeln('<info> Tag account with login '.$this->accountName.' exists on BBDD</info>');
        }

        $tagYoutubeAccount = new Tag();

        foreach ($this->locales as $locale) {
            $tagYoutubeAccount->setTitle($this->accountName, $locale);
        }

        $tagYoutubeAccount->setProperty('login', $this->accountName);

        $tagYoutubeAccount->setParent($youtubeTag);

        $this->dm->persist($tagYoutubeAccount);
        $this->dm->flush();

        $tagYoutubeAccount->setCod($tagYoutubeAccount->getId());

        $this->dm->flush();
    }

    /**
     * @param OutputInterface $output
     */
    private function migrateYoutubeTag(OutputInterface $output)
    {
        $youtubeTag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
            ['cod' => $this->tagYoutubeCod]
        );

        foreach ($this->youtubeTagProperties as $key => $value) {
            $youtubeTag->setProperty($key, $value);
        }

        $this->dm->flush();

        $output->writeln('Youtube - SKIP - Adding properties to '.$this->tagYoutubeCod);
    }

    /**
     * @param OutputInterface $output
     *
     * @return bool
     *
     * @throws \Exception
     */
    private function migrateYoutubeDocuments(OutputInterface $output)
    {
        $output->writeln('<info>***** Youtube - Updating account on documents</info>');

        $youtubeDocuments = $this->dm->getRepository('PumukitYoutubeBundle:Youtube')->findBy(
            ['youtubeAccount' => ['$exists' => false]]
        );

        if (!$youtubeDocuments) {
            $output->writeln('Youtube - SKIP - No documents to update');

            return false;
        }

        $progress = new ProgressBar($output, count($youtubeDocuments));
        $progress->setFormat('verbose');

        $progress->start();

        $tagAccount = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
            ['properties.login' => $this->accountName]
        );
        if (!$tagAccount) {
            throw new \Exception(' Youtube - ERROR - '.$this->accountName." tag doesn't exists");
        }

        $i = 0;
        foreach ($youtubeDocuments as $youtubeDocument) {
            ++$i;
            $progress->advance();
            $this->addYoutubeAccount($youtubeDocument, $tagAccount);
            if (0 === $i % 50) {
                $this->dm->flush();
            }
        }

        $this->dm->flush();
        $progress->finish();
        $output->writeln('<info>***** Youtube - End updating account on documents</info>');

        return true;
    }

    /**
     * @param Youtube $youtube
     * @param Tag     $tagAccount
     */
    private function addYoutubeAccount(Youtube $youtube, Tag $tagAccount)
    {
        $youtube->setYoutubeAccount($tagAccount->getProperty('login'));
    }

    /**
     * @param OutputInterface $output
     *
     * @throws \Exception
     */
    private function moveAllPlaylistTags(OutputInterface $output)
    {
        $youtubeTag = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
            ['cod' => $this->tagYoutubeCod]
        );

        $playlistTags = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findBy(
            [
                'properties.login' => ['$exists' => false],
                'parent.$id' => new \MongoId($youtubeTag->getId()),
            ]
        );

        if (!$playlistTags) {
            throw new \Exception(' Youtube - ERROR - playlist tags not found');
        }

        $progress = new ProgressBar($output, count($playlistTags));
        $progress->setFormat('verbose');

        $progress->start();

        $tagAccount = $this->dm->getRepository('PumukitSchemaBundle:Tag')->findOneBy(
            ['properties.login' => $this->accountName]
        );

        if (!$tagAccount) {
            throw new \Exception(' Youtube - ERROR - '.$this->accountName." tag doesn't exists");
        }

        foreach ($playlistTags as $playlistTag) {
            $progress->advance();
            $this->refactorPlaylistTag($playlistTag, $tagAccount);
        }

        $this->dm->flush();

        $progress->finish();
        $output->writeln('Youtube - SKIP - Moved all Youtube tags under Youtube account tag');
    }

    /**
     * @param Tag $playlistTag
     * @param     $tagAccount
     *
     * @return bool
     */
    private function refactorPlaylistTag(Tag $playlistTag, $tagAccount)
    {
        if ($playlistTag->getProperty('login')) {
            return false;
        }

        $playlistTag->setParent($tagAccount);
        $playlistTag->setProperty('youtube_playlist', true);

        return true;
    }

    /**
     * @return bool
     */
    private function findJsonAccount()
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
}
