<?php

declare(strict_types=1);

namespace Qruto\Wave;

use Illuminate\Contracts\Auth\Authenticatable;
use Qruto\Wave\Storage\BroadcastingEvent;

interface PresenceChannelEvent
{
    public function isLeaveEvent(BroadcastingEvent $event, Authenticatable $user): bool;

    public function isSelfLeaveEvent(BroadcastingEvent $event, Authenticatable $user): bool;

    public function fromPresenceChannel(BroadcastingEvent $event): bool;

    public function formatLeaveEventForSending(BroadcastingEvent $event);
}
