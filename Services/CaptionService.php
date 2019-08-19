<?php

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Material;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Document\Caption;

class CaptionService extends YoutubeService
{
    /**
     * @param MultimediaObject $multimediaObject
     *
     * @throws \Exception
     *
     * @return mixed
     */
    public function listAllCaptions(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        $login = $youtube->getYoutubeAccount();
        $result = $this->youtubeProcessService->listCaptions($youtube, $login);
        if ($result['error']) {
            $errorLog = __CLASS__.' ['.__FUNCTION__
                       .'] Error in retrieve captions list: '.$result['error_out'];
            $this->logger->error($errorLog);

            throw new \Exception($errorLog);
        }

        return $result['out'];
    }

    /**
     * @param MultimediaObject $multimediaObject
     * @param array            $materialIds
     *
     * @throws \Exception
     *
     * @return array
     */
    public function uploadCaption(MultimediaObject $multimediaObject, array $materialIds = [])
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        $login = $youtube->getYoutubeAccount();
        $uploaded = [];
        $result = [];
        foreach ($materialIds as $materialId) {
            $material = $multimediaObject->getMaterialById($materialId);
            if ($material) {
                $result = $this->youtubeProcessService->insertCaption($youtube, $material->getName(), $material->getLanguage(), $material->getPath(), $login);
            }
            if ($result['error']) {
                $errorLog = __CLASS__.' ['.__FUNCTION__
                  ."] Error in uploading Caption for Youtube video with id '"
                  .$youtube->getId()."' and material Id '"
                  .$materialId."': ".$result['error_out'];
                $this->logger->error($errorLog);

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
     * @param MultimediaObject $multimediaObject
     * @param array            $captionIds
     *
     * @throws \Exception
     *
     * @return int
     */
    public function deleteCaption(MultimediaObject $multimediaObject, array $captionIds = [])
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        $login = $youtube->getYoutubeAccount();
        foreach ($captionIds as $captionId) {
            $result = $this->youtubeProcessService->deleteCaption($captionId, $login);
            if ($result['error']) {
                if (false === strpos($result['error_out'], 'caption track could not be found')) {
                    $errorLog = __CLASS__.' ['.__FUNCTION__
                        ."] Error in deleting Caption for Youtube video with id '"
                        .$youtube->getId()."' and Caption id '"
                        .$captionId."': ".$result['error_out'];
                    $this->logger->error($errorLog);

                    throw new \Exception($errorLog);
                }
            }
            $youtube->removeCaptionByCaptionId($captionId);
        }
        $this->dm->persist($youtube);
        $this->dm->flush();

        return 0;
    }

    /**
     * @param array $pubChannelTags
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function createYoutubeMultimediaObjectsQueryBuilder(array $pubChannelTags)
    {
        if ($this->syncStatus) {
            $aStatus = [MultimediaObject::STATUS_PUBLISHED, MultimediaObject::STATUS_BLOCKED, MultimediaObject::STATUS_HIDDEN];
        } else {
            $aStatus = [MultimediaObject::STATUS_PUBLISHED];
        }

        return $this->mmobjRepo->createQueryBuilder()
            ->field('properties.pumukit1id')->exists(false)
            ->field('properties.origin')->notEqual('youtube')
            ->field('status')->in($aStatus)
            ->field('embeddedBroadcast.type')->equals('public')
            ->field('tags.cod')->all($pubChannelTags);
    }

    /**
     * @param Material $material
     * @param array    $output
     *
     * @throws \Exception
     *
     * @return Caption
     */
    protected function createCaption(Material $material, array $output)
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
