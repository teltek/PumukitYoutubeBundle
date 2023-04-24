<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\CaptionsListService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CaptionListCommand extends Command
{
    private $documentManager;
    private $captionListService;

    public function __construct(
        DocumentManager $documentManager,
        CaptionsListService $captionListService
    ) {
        $this->documentManager = $documentManager;
        $this->captionListService = $captionListService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('pumukit:youtube:caption:list')
            ->addOption(
                'videoId',
                null,
                InputOption::VALUE_REQUIRED,
                'Youtube Video ID'
            )
            ->setDescription('List caption for Multimedia Object')
            ->setHelp(
                <<<'EOT'
List caption for Multimedia Object

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $element = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'youtubeId' => $input->getOption('videoId'),
        ]);

        $account = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $element->getYoutubeAccount(),
        ]);

        $response = $this->captionListService->findAll($account, $element->getYoutubeId());

        var_dump($response);

        return 0;
    }
}
