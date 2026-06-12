<?php

declare(strict_types=1);

namespace Qruto\Wave;

use Closure;
use Illuminate\Http\Request;
use Qruto\Wave\Storage\BroadcastingEvent;

interface ServerSentEventSubscriber
{
    /**
     * Stream broadcast events to the client until the connection ends.
     *
     * @param  Closure(BroadcastingEvent): void  $onMessage
     * @param  string  $lastEventId  deliver events strictly after this event id
     */
    public function start(Closure $onMessage, Request $request, string $socket, string $lastEventId);
}
