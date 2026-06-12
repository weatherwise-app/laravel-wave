<?php

declare(strict_types=1);

namespace Qruto\Wave\Storage;

use Illuminate\Support\Collection;

interface BroadcastEventHistory
{
    public function getEventsFrom(string $id): Collection;

    public function pushEvent(BroadcastingEvent $event);

    public function lastEventTimestamp(): int;

    /**
     * The id of the most recent event in the history stream ("0-0" if empty).
     */
    public function latestEventId(): string;
}
