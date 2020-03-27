<?php

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Caption;

class CaptionService extends YoutubeService
{
    public function listAllCaptions(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        $result = $this->youtubeProcessService->listCaptions($youtube);
        if ($result['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       .'] Error in retrieve captions list: '.$result['error_out'];
            $this->logger->addError($errorLog);

            throw new \Exception($errorLog);
        }

        return $result['out'];
    }

    public function uploadCaption(MultimediaObject $multimediaObject, array $materialIds = [])
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        $uploaded = [];
        $result = [];
        foreach ($materialIds as $materialId) {
            $material = $multimediaObject->getMaterialById($materialId);
            if ($material) {
                $result = $this->youtubeProcessService->insertCaption($youtube, $material->getName(), $material->getLanguage(), $material->getPath());
            }
            if ($result['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error in uploading Caption for Youtube video with id '"
                  .$youtube->getId()."' and material Id '"
                  .$materialId."': ".$result['error_out'];
                $this->logger->addError($errorLog);

                throw new \Exception($errorLog);
            }
            $caption = $this->createCaption($material, $result['out']);
            $youtube->addCaption($caption);
            $uploaded[] = $result['out'];
        }
        $this->dm->persist($youtube);
        $this->dm->flush();

        return $uploaded;
    }

    /**
     * Delete.
     *
     * @param MultimediaObject $multimediaObject
     *
     * @throws \Exception
     *
     * @return int
     */
    public function deleteCaption(MultimediaObject $multimediaObject, array $captionIds = [])
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        foreach ($captionIds as $captionId) {
            $result = $this->youtubeProcessService->deleteCaption($captionId);
            if ($result['error']) {
                if (false === strpos($result['error_out'], 'caption track could not be found')) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                        ."] Error in deleting Caption for Youtube video with id '"
                        .$youtube->getId()."' and Caption id '"
                        .$captionId."': ".$result['error_out'];
                    $this->logger->addError($errorLog);

                    throw new \Exception($errorLog);
                }
            }
            $youtube->removeCaptionByCaptionId($captionId);
        }
        $this->dm->persist($youtube);
        $this->dm->flush();

        return 0;
    }

    protected function createCaption($material, $output)
    {
        $caption = new Caption();
        $caption->setMaterialId($material->getId());
        $caption->setCaptionId($output['captionid']);
        $caption->setName($output['name']);
        $caption->setLanguage($output['language']);
        $caption->setLastUpdated(new \DateTime($output['last_updated']));
        $caption->setIsDraft($output['is_draft']);

        return $caption;
    }
}
