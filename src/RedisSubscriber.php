<?php

namespace Qruto\Wave;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Redis;
use Qruto\Wave\Events\SseConnectionClosedEvent;
use Qruto\Wave\Sse\EventFactory;
use Throwable;

/**
 * Legacy pub/sub based subscriber. Holds a Redis connection in subscribe
 * mode for the lifetime of the SSE request, so it is only suitable for
 * traditional per-request runtimes (PHP-FPM). Use the default stream
 * subscriber for Octane and other long-lived application servers.
 */
class RedisSubscriber implements ServerSentEventSubscriber
{
    public function start(Closure $onMessage, Request $request, string $socket, string $lastEventId)
    {
        $connectionName = subscriptionConnectionName();

        /** @var PhpRedisConnection|PredisConnection $connection */
        $connection = Redis::connection($connectionName);

        try {
            $connection->psubscribe('*', function (string $message, string $channel) use ($onMessage) {
                $onMessage(EventFactory::fromRedisMessage($message, $channel));
            });
        } finally {
            // Release the subscribe-mode connection before anything that can
            // throw: leaked into the manager, it would poison the next
            // request served by a long-lived worker.
            try {
                $connection->disconnect();
            } catch (Throwable) {
                // The connection may already be gone.
            }

            Redis::purge($connectionName);

            event(new SseConnectionClosedEvent($request->user(), $socket));
        }
    }
}
