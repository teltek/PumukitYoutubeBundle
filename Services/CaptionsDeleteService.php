<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Exception\YoutubeQuotaExceededException;

class CaptionsDeleteService extends GoogleCaptionService
{
    private const CAPTION_NOT_FOUND = 'captionNotFound';

    private $documentManager;
    private $googleAccountService;
    private $googleApiErrorParser;

    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        GoogleApiErrorParser $googleApiErrorParser,
        LoggerInterface $logger
    ) {
        $this->googleApiErrorParser = $googleApiErrorParser;
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->logger = $logger;
    }

    public function deleteCaption(Tag $account, Youtube $youtube, array $captionsId): bool
    {
        $failed = false;
        foreach ($captionsId as $captionId) {
            try {
                $result = $this->delete($account, $captionId);
            } catch (\Exception $exception) {
                $errorLog = sprintf('[YouTube] Remove caption for Youtube document %s failed. Error: %s', $youtube->getId(), $exception->getMessage());
                $this->logger->error($errorLog);

                try {
                    $parsed = $this->googleApiErrorParser->parse($exception);
                } catch (YoutubeQuotaExceededException $quotaException) {
                    $this->documentManager->flush();

                    throw $quotaException;
                }

                if (self::CAPTION_NOT_FOUND === $parsed->reason()) {
                    $youtube->removeCaptionByCaptionId($captionId);

                    continue;
                }

                $failed = true;
                $youtube->setCaptionUpdateError(Error::create($parsed->reason(), $parsed->message(), new \DateTime(), $parsed->raw()));

                continue;
            }

            $youtube->removeCaptionByCaptionId($captionId);
        }

        if (!$failed) {
            $youtube->removeCaptionUpdateError();
        }
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
