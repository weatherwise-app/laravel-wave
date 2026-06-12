<?php

declare(strict_types=1);

namespace Qruto\Wave;

use Illuminate\Foundation\Application;

if (! function_exists('laravel11OrHigher')) {
    function laravel11OrHigher(): bool
    {
        return explode('.', Application::VERSION)[0] >= 11;
    }
}

if (! function_exists('Qruto\Wave\subscriptionConnectionName')) {
    function subscriptionConnectionName(): string
    {
        return config('broadcasting.connections.redis.connection', 'default').'-subscription';
    }
}
