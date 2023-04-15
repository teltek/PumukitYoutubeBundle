<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;

class CaptionsDeleteService extends GoogleCaptionService
{
    private $documentManager;
    private $googleAccountService;

    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        LoggerInterface $logger
    )
    {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->logger = $logger;
    }

    public function deleteCaption(Tag $account, Youtube $youtube, array $captionsId): bool
    {
        foreach ($captionsId as $captionId) {
            $result = $this->delete($account, $captionId);
            if (!$result) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                    ."] Error in deleting Caption for Youtube video with id '"
                    .$youtube->getId()."' and Caption id '"
                    .$captionId;
                $this->logger->error($errorLog);

                return false;
            }

            $youtube->removeCaptionByCaptionId($captionId);
        }

        $this->documentManager->flush();

        return true;
    }

    private function delete(Tag $account, string $captionId)
    {
        $service =$this->googleAccountService->googleServiceFromAccount($account);

        return $service->captions->delete($captionId);
    }

}
