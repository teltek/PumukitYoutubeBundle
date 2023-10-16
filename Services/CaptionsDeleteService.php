<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
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
    ) {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->logger = $logger;
    }

    public function deleteCaption(Tag $account, Youtube $youtube, array $captionsId): bool
    {
        foreach ($captionsId as $captionId) {
            try {
                $result = $this->delete($account, $captionId);
            } catch (\Exception $exception) {
                $errorLog = sprintf('[YouTube] Remove caption for Youtube document %s failed. Error: %s', $youtube->getId(), $exception->getMessage());
                $this->logger->error($errorLog);
                $error = json_decode($exception->getMessage(), true, 512, JSON_THROW_ON_ERROR);
                $error = \Pumukit\YoutubeBundle\Document\Error::create(
                    $error['error']['errors'][0]['reason'],
                    $error['error']['errors'][0]['message'],
                    new \DateTime(),
                    $error['error']
                );
                $youtube->setCaptionUpdateError($error);

                continue;
            }

            $youtube->removeCaptionByCaptionId($captionId);
        }

        $youtube->removeCaptionUpdateError();
        $this->documentManager->flush();

        return true;
    }

    private function delete(Tag $account, string $captionId)
    {
        $infoLog = sprintf('[YouTube] Caption delete: %s ', $captionId);
        $this->logger->info($infoLog);

        $service = $this->googleAccountService->googleServiceFromAccount($account);

        return $service->captions->delete($captionId);
    }
}
