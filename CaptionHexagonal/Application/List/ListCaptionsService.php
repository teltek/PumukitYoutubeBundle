<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\List;

use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Repository\YoutubeCaptionApiInterface;

final class ListCaptionsService
{
    private YoutubeCaptionApiInterface $youtubeCaptionApi;
    private ListCaptionsValidator $validator;

    public function __construct(
        YoutubeCaptionApiInterface $youtubeCaptionApi,
        ListCaptionsValidator $validator
    ) {
        $this->youtubeCaptionApi = $youtubeCaptionApi;
        $this->validator = $validator;
    }

    public function __invoke(ListCaptionsRequest $request): ListCaptionsResponse
    {
        $this->validator->validate($request);

        $captions = $this->youtubeCaptionApi->list($request->getYoutubeId());

        return new ListCaptionsResponse($request->getYoutubeId(), $captions);
    }
}
