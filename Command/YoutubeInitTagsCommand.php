<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsCommand(name: 'pumukit:youtube:init:pubchannel', description: 'Create the YouTube publication channel and the YouTube tag if they do not exist')]
#[AutoconfigureTag('pumukit.setup')]
class YoutubeInitTagsCommand extends Command
{
    public function __construct(private readonly DocumentManager $documentManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Not needed any more: the command only creates the tags that do not exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $publicationChannel = $this->createTag(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE, 'YouTubeEDU', 'PUBCHANNELS', false, $output);
        if (false === $publicationChannel) {
            return Command::FAILURE;
        }
        if ($publicationChannel instanceof Tag) {
            $publicationChannel->setProperty('modal_path', 'pumukityoutube_modal_index');
            $publicationChannel->setProperty('advanced_configuration', 'pumukityoutube_advance_configuration_index');
        }

        $playlistTag = $this->createTag(PumukitYoutubeBundle::YOUTUBE_TAG_CODE, 'YouTube', 'ROOT', true, $output);
        if (false === $playlistTag) {
            return Command::FAILURE;
        }
        if ($playlistTag instanceof Tag) {
            $playlistTag->setProperty('hide_in_tag_group', true);
        }

        $this->documentManager->flush();

        return Command::SUCCESS;
    }

    private function createTag(string $code, string $title, string $parentCode, bool $metatag, OutputInterface $output): bool|Tag|null
    {
        $repository = $this->documentManager->getRepository(Tag::class);
        if ($tag = $repository->findOneBy(['cod' => $code])) {
            $output->writeln(sprintf('The tag %s already exists.', $code));

            return null;
        }

        $parent = $repository->findOneBy(['cod' => $parentCode]);
        if (!$parent instanceof Tag) {
            $output->writeln(sprintf('<error>There is no tag %s to be the parent of %s: run pumukit:init:repo first.</error>', $parentCode, $code));

            return false;
        }

        $tag = new Tag();
        $tag->setCod($code);
        $tag->setMetatag($metatag);
        $tag->setDisplay(true);
        foreach (['es', 'gl', 'en'] as $locale) {
            $tag->setTitle($title, $locale);
        }
        $tag->setParent($parent);
        $this->documentManager->persist($tag);
        $output->writeln(sprintf('The tag %s is created.', $code));

        return $tag;
    }
}
