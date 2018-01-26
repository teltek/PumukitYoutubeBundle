<?php

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\NotificationBundle\Services\SenderService;

class YoutubeService
{
    private $dm;
    private $router;
    private $logger;
    private $senderService;
    private $translator;
    private $youtubeRepo;
    private $mmobjRepo;
    private $youtubeProcessService;

    public function __construct(DocumentManager $documentManager, Router $router, LoggerInterface $logger, SenderService $senderService = null, TranslatorInterface $translator, YoutubeProcessService $youtubeProcessService, $locale, $pumukitLocales)
    {
        $this->dm = $documentManager;
        $this->router = $router;
        $this->logger = $logger;
        $this->senderService = $senderService;
        $this->translator = $translator;
        $this->youtubeProcessService = $youtubeProcessService;
        $this->youtubeRepo = $this->dm->getRepository('PumukitYoutubeBundle:Youtube');
        $this->mmobjRepo = $this->dm->getRepository('PumukitSchemaBundle:MultimediaObject');
    }

    public function listAll(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        $result = $this->youtubeProcessService->listCaptions($youtube);
        // TODO
    }

    public function upload(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        // TODO
        /* $result = $this->youtubeProcessService->insertCaption($youtube, $name, $language, $file); */
    }

    /**
     * Delete.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @return int
     *
     * @throws \Exception
     */
    public function delete(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        // TODO
        /* $result = $this->youtubeProcessService->deleteCaption($captionId); */
    }