<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\ValueObject;

enum PublicationStatus: string
{
    case PENDING = 'pending';
    case UPLOADED = 'uploaded';
    case UPDATED = 'updated';
    case IN_PLAYLIST = 'in_playlist';
    case COMPLETED = 'completed';
    case REMOVED = 'removed';
    case ERROR = 'error';
    case RETRY = 'retry';
}
