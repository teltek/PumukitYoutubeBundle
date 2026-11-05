<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Material;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Caption;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Exception\YoutubeQuotaExceededException;

class CaptionsInsertService extends GoogleCaptionService
{
    private $documentManager;
    private $googleAccountService;
    private $captionsDeleteService;
    private $captionsListService;
    private $googleApiErrorParser;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        CaptionsDeleteService $captionsDeleteService,
        CaptionsListService $captionsListService,
        GoogleApiErrorParser $googleApiErrorParser,
        LoggerInterface $logger
    ) {
        $this->googleApiErrorParser = $googleApiErrorParser;
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->captionsDeleteService = $captionsDeleteService;
        $this->captionsListService = $captionsListService;
        $this->logger = $logger;
    }

    public function uploadCaption(Youtube $youtube, MultimediaObject $multimediaObject, array $materialIds = []): bool
    {
        foreach ($materialIds as $materialId) {
            $material = $multimediaObject->getMaterialById($materialId);
            if (!$material) {
                continue;
            }

            try {
                $videoId = $multimediaObject->getProperty('youtube');
                if (!$videoId) {
                    $this->logger->error('[YouTube] Multimedia Object with ID '.$multimediaObject->getId().' doesnt have youtube property');

                    continue;
                }

                $account = $this->documentManager->getRepository(Tag::class)->findOneBy([
                    'properties.login' => $youtube->getYoutubeAccount(),
                ]);

                if (!empty($youtube->getCaptionUpdateError()) && 'captionExists' === $youtube->getCaptionUpdateError()->id()) {
                    $captionsUploaded = $this->captionsListService->findAll($account, $youtube->getYoutubeId());
                    foreach ($captionsUploaded->getItems() as $caption) {
                        if ($caption->getSnippet()->getName() === $material->getName() && $caption->getSnippet()->getLanguage() === $material->getLanguage()) {
                            $this->captionsDeleteService->deleteCaption($account, $youtube, [$caption->getId()]);
                        }
                    }
                }

                if (!$this->deleteCaptionsReplacedBy($account, $youtube, $material)) {
                    continue;
                }

                $result = $this->insert($account, $material, $youtube->getYoutubeId());

                $caption = $this->createCaptionDocument($material, $result);
                $youtube->addCaption($caption);
                $youtube->removeCaptionUpdateError();
            } catch (YoutubeQuotaExceededException $exception) {
                $this->documentManager->flush();

                throw $exception;
            } catch (\Exception $exception) {
                try {
                    $parsed = $this->googleApiErrorParser->parse($exception);
                } catch (YoutubeQuotaExceededException $quotaException) {
                    $this->documentManager->flush();

                    throw $quotaException;
                }

                $youtube->setCaptionUpdateError(Error::create($parsed->reason(), $parsed->message(), new \DateTime(), $parsed->raw()));
                $errorLog = sprintf('[YouTube] Video %s and material %s could\'t upload. Error: %s', $multimediaObject->getId(), $material->getId(), $exception->getMessage());
                $this->logger->error($errorLog);
            }
        }

        $this->documentManager->flush();

        return true;
    }

    public function isOutdated(Caption $caption, Material $material): bool
    {
        $fileHash = $this->fileHash($material);
        if (null === $fileHash) {
            return false;
        }

        if (null === $caption->getFileHash()) {
            $caption->setFileHash($fileHash);

            return false;
        }

        return $fileHash !== $caption->getFileHash();
    }

    private function deleteCaptionsReplacedBy(Tag $account, Youtube $youtube, Material $material): bool
    {
        $language = strtolower($material->getLanguage());
        $captionIds = [];
        foreach ($youtube->getCaptions() as $caption) {
            if ($caption->getMaterialId() === $material->getId() || ('' !== $language && strtolower($caption->getLanguage()) === $language)) {
                $captionIds[] = $caption->getCaptionId();
            }
        }

        if (!$captionIds) {
            return true;
        }

        $this->captionsDeleteService->deleteCaption($account, $youtube, $captionIds);

        foreach ($captionIds as $captionId) {
            if ($youtube->getCaptionByCaptionId($captionId)) {
                return false;
            }
        }

        return true;
    }

    private function fileHash(Material $material): ?string
    {
        $path = $material->getPath();
        if (!$path || !is_file($path)) {
            return null;
        }

        return sha1_file($path) ?: null;
    }

    private function insert(Tag $account, Material $material, string $videoId): \Google\Service\YouTube\Caption
    {
        $infoLog = sprintf('[YouTube] Caption with material ID %s insert on video %s', $material->getId(), $videoId);
        $this->logger->info($infoLog);

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

    private function createCaptionItem(Material $material, string $videoId): \Google\Service\YouTube\Caption
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
        if ($fileHash = $this->fileHash($material)) {
            $caption->setFileHash($fileHash);
        }

        $this->documentManager->persist($caption);

        return $caption;
    }
}
