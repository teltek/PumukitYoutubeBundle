<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload;

use Pumukit\YoutubeBundle\Document\Youtube;

final class UploadVideoResponse
{
    private Youtube $youtube;
    private ?string $youtubeId;

    public function __construct(Youtube $youtube, ?string $youtubeId = null)
    {
        $this->youtube = $youtube;
        $this->youtubeId = $youtubeId;
    }

    public function getYoutube(): Youtube
    {
        return $this->youtube;
    }

    public function getYoutubeId(): ?string
    {
        return $this->youtubeId;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->youtube->getId(),
            'multimediaObjectId' => $this->youtube->getMultimediaObjectId(),
            'youtubeId' => $this->youtubeId,
            'status' => $this->youtube->getStatus(),
        ];
    }
}
