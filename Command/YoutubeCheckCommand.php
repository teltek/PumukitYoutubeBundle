<?php

namespace Pumukit\YoutubeBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeCheckCommand extends ContainerAwareCommand
{
    private $dm = null;
    private $tagRepo = null;
    private $mmobjRepo = null;
    private $youtubeRepo = null;

    private $youtubeService;
    private $youtubeTag;

    private $logger;

    protected function configure()
    {
        $this
            ->setName('youtube:check')
            ->setDescription('Check the YouTube configuration and API status')
            ->setHelp(
                <<<'EOT'
Check all the accounts configured into in PuMuKIT.


EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->checkAccounts();
    }

    private function checkAccounts()
    {
        foreach ($this->youtubeTag->getChildren() as $account) {
            $login = $account->getProperty('login');
            if (!$login) {
                throw new \Exception(sprintf('Youtube account tag %s without login property', $account->getCod()));
            }
            try {
                $this->youtubeService->getAllYoutubePlaylists($login);
            } catch (\Exception $e) {
                throw new \Exception(sprintf('Error getting playlist of account %s. To debug it execute `python getAllPlaylists.py --account %s`', $login, $login));
            }
        }
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->dm = $this->getContainer()->get('doctrine_mongodb')->getManager();
        $this->tagRepo = $this->dm->getRepository('PumukitSchemaBundle:Tag');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');

        $this->youtubeTag = $this->tagRepo->findOneBy(array('cod' => 'YOUTUBE'));
        if (!$this->youtubeTag) {
            throw new \Exception('No tag with code YOUTUBE');
        }

        $this->youtubeService = $this->getContainer()->get('pumukityoutube.youtube');

        $this->logger = $this->getContainer()->get('monolog.logger.youtube');
    }
}
