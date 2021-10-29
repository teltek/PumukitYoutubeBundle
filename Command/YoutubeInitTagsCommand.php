<?php

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Repository\TagRepository;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class YoutubeInitTagsCommand extends ContainerAwareCommand
{
    /**
     * @var DocumentManager
     */
    private $dm;
    /**
     * @var TagRepository
     */
    private $tagRepo;

    protected function configure()
    {
        $this->setName('youtube:init:tags')->setDescription(
            'Load Youtube tag data fixture to your database'
        )->addOption('force', null, InputOption::VALUE_NONE, 'Set this parameter to execute this action')->setHelp(
            <<<'EOT'
Command to load a controlled Youtube tags data into a database. Useful for init Youtube environment.

The --force parameter has to be used to actually drop the database.

EOT
        );
    }

    /**
     * @throws \Exception
     *
     * @return int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb.odm.document_manager');
        $this->tagRepo = $this->dm->getRepository(Tag::class);
        if ($input->getOption('force')) {
            $youtubePublicationChannelTag = $this->createTagWithCode(Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE, 'YouTubeEDU', 'PUBCHANNELS', false);
            $youtubePublicationChannelTag->setProperty('modal_path', 'pumukityoutube_modal_index');
            $youtubePublicationChannelTag->setProperty(
                'advanced_configuration',
                'pumukityoutube_advance_configuration_index'
            );
            $this->dm->persist($youtubePublicationChannelTag);
            $this->dm->flush();
            $output->writeln(
                'Tag persisted - new id: '.$youtubePublicationChannelTag->getId().
                ' cod: '.$youtubePublicationChannelTag->getCod()
            );
            $youtubePlaylistTag = $this->createTagWithCode(Youtube::YOUTUBE_TAG_CODE, 'YouTube', 'ROOT', true);
            $youtubePlaylistTag->setProperty(
                'hide_in_tag_group',
                true
            );
            $this->dm->flush();
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

    /**
     * @param string $code
     * @param string $title
     * @param null   $tagParentCode
     * @param bool   $metatag
     *
     * @throws \Exception
     *
     * @return Tag
     */
    private function createTagWithCode($code, $title, $tagParentCode = null, $metatag = false)
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
        $this->dm->persist($tag);
        $this->dm->flush();

        return $tag;
    }
}
