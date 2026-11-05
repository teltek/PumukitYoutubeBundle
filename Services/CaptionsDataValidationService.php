<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\Material;
use Pumukit\SchemaBundle\Document\MultimediaObject;

class CaptionsDataValidationService extends CommonDataValidationService
{
    private $youtubeConfigurationService;

    public function __construct(DocumentManager $documentManager, YoutubeConfigurationService $youtubeConfigurationService)
    {
        $this->youtubeConfigurationService = $youtubeConfigurationService;

        parent::__construct($documentManager);
    }

    public function materialsForYoutube(MultimediaObject $multimediaObject): array
    {
        $materials = [];
        foreach ($multimediaObject->getMaterials() as $material) {
            if ($material->isHide() || !in_array($material->getMimeType(), $this->youtubeConfigurationService->allowedCaptionMimeTypes(), true)) {
                continue;
            }

            $key = strtolower($material->getLanguage()) ?: (string) $material->getId();
            if (!isset($materials[$key]) || $this->createdAt($material) >= $this->createdAt($materials[$key])) {
                $materials[$key] = $material;
            }
        }

        return $materials;
    }

    private function createdAt(Material $material): int
    {
        return (new ObjectId((string) $material->getId()))->getTimestamp();
    }
}
