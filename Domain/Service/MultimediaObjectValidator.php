<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Service;

use Psr\Log\LoggerInterface;

/**
 * Validates if a MultimediaObject is eligible for YouTube upload.
 */
class MultimediaObjectValidator
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Validates if MultimediaObject meets YouTube upload requirements.
     *
     * @param object $multimediaObject The MultimediaObject to validate
     * @return bool True if valid, false otherwise
     */
    public function isValidForYoutubeUpload(object $multimediaObject): bool
    {
        // DEBUG: Log immediately at entry
        $this->logger->info('[YouTubeValidator] ENTRY POINT - Validation started', [
            'class' => get_class($multimediaObject),
        ]);

        // Check if object exists
        if (!$multimediaObject) {
            $this->logger->warning('[YouTubeValidator] MultimediaObject is null');
            return false;
        }

        // Check if object has required methods (duck typing)
        if (!method_exists($multimediaObject, 'getId')) {
            $this->logger->warning('[YouTubeValidator] MultimediaObject does not have getId method');
            return false;
        }

        // Validate required metadata
        if (!$this->hasRequiredMetadata($multimediaObject)) {
            $this->logger->info('[YouTubeValidator] Missing required metadata', [
                'multimediaObjectId' => $multimediaObject->getId(),
            ]);
            return false;
        }

        // Validate has video tracks
        if (!$this->hasValidVideoTracks($multimediaObject)) {
            $this->logger->info('[YouTubeValidator] No valid video tracks found', [
                'multimediaObjectId' => $multimediaObject->getId(),
            ]);
            return false;
        }

        // Check publication eligibility
        if (!$this->isEligibleForPublication($multimediaObject)) {
            $this->logger->info('[YouTubeValidator] Not eligible for publication', [
                'multimediaObjectId' => $multimediaObject->getId(),
            ]);
            return false;
        }

        $this->logger->debug('[YouTubeValidator] MultimediaObject is valid for YouTube upload', [
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);

        return true;
    }

    private function hasRequiredMetadata(object $multimediaObject): bool
    {
        // Check title
        if (method_exists($multimediaObject, 'getTitle')) {
            $title = $multimediaObject->getTitle();
            if (empty($title)) {
                return false;
            }
        } else {
            return false;
        }

        // Check if has basic info
        // Description is optional, but good to have
        if (method_exists($multimediaObject, 'getDescription')) {
            // Description exists, that's good
        }

        return true;
    }

    private function hasValidVideoTracks(object $multimediaObject): bool
    {
        if (!method_exists($multimediaObject, 'getTracks')) {
            $this->logger->warning('[YouTubeValidator] MultimediaObject does not have getTracks method');
            return false;
        }

        $tracks = $multimediaObject->getTracks();
        
        if (empty($tracks)) {
            $this->logger->warning('[YouTubeValidator] MultimediaObject has no tracks');
            return false;
        }

        // Check if at least one track is a video track (not only audio)
        foreach ($tracks as $track) {
            // Duck typing: check if track has metadata() method and isOnlyAudio()
            if (!method_exists($track, 'metadata')) {
                continue;
            }

            $metadata = $track->metadata();
            
            if (!method_exists($metadata, 'isOnlyAudio')) {
                continue;
            }

            if (!$metadata->isOnlyAudio()) {
                // Found a video track (has video, not only audio)
                $this->logger->info('[YouTubeValidator] Found valid video track', [
                    'trackId' => method_exists($track, 'getId') ? $track->getId() : 'unknown',
                ]);
                return true;
            }
        }

        $this->logger->info('[YouTubeValidator] No video tracks found (all tracks are audio-only)');
        return false;
    }

    private function isEligibleForPublication(object $multimediaObject): bool
    {
        // Check if object has status method
        if (!method_exists($multimediaObject, 'getStatus')) {
            // If no status method, assume it's eligible
            $this->logger->info('[YouTubeValidator] No getStatus method, assuming eligible');
            return true;
        }

        $status = $multimediaObject->getStatus();

        // PuMuKIT Status codes:
        // STATUS_PUBLISHED = 0
        // STATUS_BLOCKED = 1
        // STATUS_HIDDEN = 2
        // STATUS_NEW = -1
        // STATUS_PROTOTYPE = -2
        
        // Allow uploads for: PUBLISHED (0), HIDDEN (2), or any negative status (NEW, PROTOTYPE)
        // Block uploads only for: BLOCKED (1)
        if (is_int($status)) {
            $eligible = $status !== 1; // Not BLOCKED
            
            $this->logger->info('[YouTubeValidator] Publication eligibility check', [
                'status' => $status,
                'eligible' => $eligible,
            ]);
            
            return $eligible;
        }

        $this->logger->info('[YouTubeValidator] Status is not an integer, assuming eligible');
        return true;
    }
}
