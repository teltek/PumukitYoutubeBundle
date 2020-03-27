<?php

namespace Pumukit\YoutubeBundle\Command;

use Pumukit\SchemaBundle\Document\Tag;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeInitTagsCommand extends ContainerAwareCommand
{
    private $dm;
    private $tagRepo;

    protected function configure()
    {
        $this
            ->setName('youtube:init:tags')
            ->setDescription('Load Youtube tag data fixture to your database')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')
            ->setHelp(
                <<<'EOT'
Command to load a controlled Youtube tags data into a database. Useful for init Youtube environment.

The --force parameter has to be used to actually drop the database.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        if ($input->getOption('force')) {
            $youtubePublicationChannelTag = $this->createTagWithCode('PUCHYOUTUBE', 'YouTubeEDU', 'PUBCHANNELS', false);
            $youtubePublicationChannelTag->setProperty('modal_path', 'pumukityoutube_modal_index');
            $this->dm->persist($youtubePublicationChannelTag);
            $this->dm->flush();
            $output->writeln('Tag persisted - new id: '.$youtubePublicationChannelTag->getId().' cod: '.$youtubePublicationChannelTag->getCod());
            $youtubePlaylistTag = $this->createTagWithCode('YOUTUBE', 'YouTube Playlists', 'ROOT', true);
            $output->writeln('Tag persisted - new id: '.$youtubePlaylistTag->getId().' cod: '.$youtubePlaylistTag->getCod());
        } else {
            $output->writeln('<error>ATTENTION:</error> This operation should not be executed in a production environment without backup.');
            $output->writeln('');
            $output->writeln('Please run the operation with --force to execute.');

            return -1;
        }

        return 0;
    }

    private function createTagWithCode($code, $title, $tagParentCode = null, $metatag = false)
    {
        if ($tag = $this->tagRepo->findOneByCod($code)) {
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
            if ($parent = $this->tagRepo->findOneByCod($tagParentCode)) {
                $tag->setParent($parent);
            } else {
                throw new \Exception('Nothing done - There is no tag in the database with code '.$tagParentCode.' to be the parent tag');
            }
        }
        $this->dm->persist($tag);
        $this->dm->flush();

        return $tag;
    }
}
