<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\UpdatePublication;

final class UpdatePublicationRequest
{
    private string $multimediaObjectId;
    private string $privacy;

    public function __construct(string $multimediaObjectId, string $privacy)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->privacy = $privacy;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getPrivacy(): string
    {
        return $this->privacy;
    }
}
