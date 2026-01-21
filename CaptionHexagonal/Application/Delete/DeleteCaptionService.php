<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Delete;

use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Event\CaptionDeletedEvent;
use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Repository\YoutubeCaptionApiInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DeleteCaptionService
{
    private YoutubeCaptionApiInterface $youtubeCaptionApi;
    private EventDispatcherInterface $eventDispatcher;
    private DeleteCaptionValidator $validator;

    public function __construct(
        YoutubeCaptionApiInterface $youtubeCaptionApi,
        EventDispatcherInterface $eventDispatcher,
        DeleteCaptionValidator $validator
    ) {
        $this->youtubeCaptionApi = $youtubeCaptionApi;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(DeleteCaptionRequest $request): DeleteCaptionResponse
    {
        $this->validator->validate($request);

        $result = $this->youtubeCaptionApi->delete($request->getYoutubeId(), $request->getCaptionId());

        if ($result) {
            $this->eventDispatcher->dispatch(
                new CaptionDeletedEvent($request->getCaptionId(), $request->getYoutubeId())
            );
        }

        return new DeleteCaptionResponse($request->getCaptionId(), $result);
    }
}
