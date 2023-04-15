<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Material;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Caption;
use Pumukit\YoutubeBundle\Document\Youtube;

class CaptionsInsertService extends GoogleCaptionService
{
    private $documentManager;
    private $googleAccountService;

    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->logger = $logger;
    }

    public function uploadCaption(Youtube $youtube, MultimediaObject $multimediaObject, array $materialIds = []): bool
    {
        // $login = $youtube->getYoutubeAccount();
        foreach ($materialIds as $materialId) {
            $material = $multimediaObject->getMaterialById($materialId);
            if (!$material) {
                continue;
            }

            try {
                // $result = $this->youtubeProcessService->insertCaption($youtube, $material->getName(), $material->getLanguage(), $material->getPath(), $login);
                $videoId = $multimediaObject->getProperty('youtube');
                if (!$videoId) {
                    $this->logger->error('Multimedia Object with ID '.$multimediaObject->getId().' doesnt have youtube property');

                    continue;
                }

                $account = $this->documentManager->getRepository(Tag::class)->findOneBy([
                    'properties.login' => $youtube->getYoutubeAccount(),
                ]);

                // TODO: Si existe un caption con el mismo nombre en YT para el mismo idioma da error.
                $result = $this->insert($account, $material, $youtube->getYoutubeId());

                $caption = $this->createCaptionDocument($material, $result);
                $youtube->addCaption($caption);

                // $uploaded[] = $result;
            } catch (\Exception $exception) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                    ."] Error in uploading Caption for Youtube video with id '"
                    .$youtube->getId()."' and material Id '"
                    .$materialId."': ".$exception->getMessage();
                $this->logger->error($errorLog);
            }

/*
            if ($result['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                    ."] Error in uploading Caption for Youtube video with id '"
                    .$youtube->getId()."' and material Id '"
                    .$materialId."': ".$result['error_out'];
                $this->logger->error($errorLog);

                continue;
            }*/
            /*$caption = $this->createCaption($material, $result['out']);
            $youtube->addCaption($caption);
            $uploaded[] = $result['out'];*/
        }

        // $this->documentManager->persist($youtube);
        $this->documentManager->flush();

        return true;
    }

    private function insert(Tag $account, Material $material, string $videoId): \Google\Service\YouTube\Caption
    {
        $service = $this->googleAccountService->googleServiceFromAccount($account);
        $caption = $this->createCaptionItem($material, $videoId);

        return $service->captions->insert(
            'snippet',
            $caption,
            [
                'sync' => false,
                'data' => file_get_contents($material->getPath()),
                'mimeType' => '*/*',
                'uploadType' => 'multipart',
            ]
        );
    }

    private function createCaptionItem(Material $material, string $videoId): \Google_Service_YouTube_Caption
    {
        $captionSnippet = $this->createCaptionSnippet($material, $videoId);

        $caption = $this->createGoogleServiceYoutubeCaption();
        $caption->setSnippet($captionSnippet);

        return $caption;
    }

    private function createCaptionDocument(Material $material, \Google\Service\YouTube\Caption $youtubeCaption): Caption
    {
        $caption = new Caption();
        $caption->setMaterialId($material->getId());
        $caption->setCaptionId($youtubeCaption->getId());
        $caption->setName($youtubeCaption->getSnippet()->getName());
        $caption->setLanguage($youtubeCaption->getSnippet()->getLanguage());
        $caption->setLastUpdated(new \DateTime($youtubeCaption->getSnippet()->getLastUpdated()));
        $caption->setIsDraft($youtubeCaption->getSnippet()->getIsDraft());

        $this->documentManager->persist($caption);

        return $caption;
    }
}
