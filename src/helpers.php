<?php

declare(strict_types=1);

namespace Qruto\Wave;

if (! function_exists('Qruto\Wave\subscriptionConnectionName')) {
    function subscriptionConnectionName(): string
    {
        return config('broadcasting.connections.redis.connection', 'default').'-subscription';
    }
}
