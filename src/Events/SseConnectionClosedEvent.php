<?php

declare(strict_types=1);

namespace Qruto\Wave\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Queue\SerializesModels;

class SseConnectionClosedEvent
{
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public ?Authenticatable $user, public string $connectionId) {}
}
