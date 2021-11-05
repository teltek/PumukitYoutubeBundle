<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeInitTagsCommand extends Command
{
    private $documentManager;
    private $tagRepo;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
        $this->tagRepo = $this->documentManager->getRepository(Tag::class);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('pumukit:youtube:init:pubchannel')->setDescription(
            'Load Youtube tag data fixture to your database'
        )->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')->setHelp(
            <<<'EOT'
Command to load a controlled Youtube tags data into a database. Useful for init Youtube environment.

The --force parameter has to be used to actually drop the database.

EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tagRepo = $this->documentManager->getRepository(Tag::class);
        if ($input->getOption('force')) {
            $youtubePublicationChannelTag = $this->createTagWithCode(Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE, 'YouTubeEDU', 'PUBCHANNELS', false);
            $youtubePublicationChannelTag->setProperty('modal_path', 'pumukityoutube_modal_index');
            $youtubePublicationChannelTag->setProperty(
                'advanced_configuration',
                'pumukityoutube_advance_configuration_index'
            );
            $this->documentManager->persist($youtubePublicationChannelTag);
            $this->documentManager->flush();
            $output->writeln(
                'Tag persisted - new id: '.$youtubePublicationChannelTag->getId().
                ' cod: '.$youtubePublicationChannelTag->getCod()
            );
            $youtubePlaylistTag = $this->createTagWithCode(Youtube::YOUTUBE_TAG_CODE, 'YouTube', 'ROOT', true);
            $youtubePlaylistTag->setProperty(
                'hide_in_tag_group',
                true
            );
            $this->documentManager->flush();
            $output->writeln(
                'Tag persisted - new id: '.$youtubePlaylistTag->getId().' cod: '.$youtubePlaylistTag->getCod()
            );
        } else {
            $output->writeln(
                '<error>ATTENTION:</error> This operation should not be executed in a production environment without backup.'
            );
            $output->writeln('');
            $output->writeln('Please run the operation with --force to execute.');

            return -1;
        }

        return 0;
    }

    private function createTagWithCode(string $code, string $title, ?string $tagParentCode = null, bool $metatag = false): Tag
    {
        if ($tag = $this->tagRepo->findOneBy(['cod' => $code])) {
            throw new \Exception('Nothing done - Tag retrieved from DB id: '.$tag->getId().' cod: '.$tag->getCod());
        }
        $tag = new Tag();
        $tag->setCod($code);
        $tag->setMetatag($metatag);
        $tag->setDisplay(true);
        $tag->setTitle($title, 'es');
        $tag->setTitle($title, 'gl');
        $tag->setTitle($title, 'en');
        if ($tagParentCode) {
            if ($parent = $this->tagRepo->findOneBy(['cod' => $tagParentCode])) {
                $tag->setParent($parent);
            } else {
                throw new \Exception(
                    'Nothing done - There is no tag in the database with code '.$tagParentCode.' to be the parent tag'
                );
            }
        }
        $this->documentManager->persist($tag);
        $this->documentManager->flush();

        return $tag;
    }
}
