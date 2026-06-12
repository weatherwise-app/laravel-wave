<?php

namespace Qruto\Wave;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Redis;
use Qruto\Wave\Events\SseConnectionClosedEvent;
use Qruto\Wave\Storage\BroadcastEventHistoryRedisStream;
use Qruto\Wave\Storage\BroadcastingEvent;
use Throwable;

/**
 * Delivers events by blocking reads (XREAD BLOCK) on the same Redis stream
 * that stores the broadcast event history. Unlike the pub/sub subscriber it
 * never puts a connection into subscribe mode, detects client disconnects via
 * heartbeats, cleans up in-band (no shutdown functions) and supports a
 * bounded connection lifetime — all requirements for long-lived application
 * servers such as Laravel Octane.
 */
class RedisStreamSubscriber implements ServerSentEventSubscriber
{
    protected const READ_BATCH_SIZE = 100;

    public function start(Closure $onMessage, Request $request, string $socket, string $lastEventId)
    {
        $connectionName = subscriptionConnectionName();

        /** @var PhpRedisConnection|PredisConnection $connection */
        $connection = Redis::connection($connectionName);

        $startedAt = now()->getTimestamp();
        $lastId = $lastEventId;

        try {
            while ($this->shouldContinue($startedAt)) {
                $events = $this->readNewEvents($connection, $lastId, $this->blockMilliseconds($startedAt));

                if ($events === []) {
                    $this->sendHeartbeat();
                }

                foreach ($events as $event) {
                    $lastId = $event->id;

                    // Other connections' lifecycle markers on the system
                    // channel are not meant for this client.
                    if ($event->channel === 'general' && $event->name === 'connected') {
                        continue;
                    }

                    $onMessage($event);
                }

                if (connection_aborted() !== 0) {
                    break;
                }
            }
        } finally {
            // Release the connection before anything that can throw: it must
            // never outlive the request on a long-lived worker.
            try {
                $connection->disconnect();
            } catch (Throwable) {
                // The connection may already be gone.
            }

            Redis::purge($connectionName);

            event(new SseConnectionClosedEvent($request->user(), $socket));
        }
    }

    protected function shouldContinue(int $startedAt): bool
    {
        $lifetime = (int) config('wave.max_connection_lifetime', 0);

        return $lifetime <= 0
            || now()->getTimestamp() - $startedAt < $lifetime;
    }

    /**
     * How long the next read may block: the configured read timeout, clamped
     * to the remaining connection lifetime so the stream closes on schedule.
     */
    protected function blockMilliseconds(int $startedAt): int
    {
        $blockMs = max(1000, (int) (config('wave.stream_read_timeout', 5) * 1000));
        $lifetime = (int) config('wave.max_connection_lifetime', 0);

        if ($lifetime <= 0) {
            return $blockMs;
        }

        $remainingMs = ($startedAt + $lifetime - now()->getTimestamp()) * 1000;

        return max(1, min($blockMs, $remainingMs));
    }

    /**
     * @param  Connection|\Illuminate\Contracts\Redis\Connection  $connection
     * @return BroadcastingEvent[]
     */
    protected function readNewEvents($connection, string $lastId, int $blockMs): array
    {
        if ($connection instanceof PredisConnection) {
            // Predis' native XREAD does not apply the configured key prefix
            // (unlike its other stream commands), so issue it raw against
            // the explicitly prefixed key.
            $entries = $this->normalizePredisEntries($connection->executeRaw([
                'XREAD',
                'COUNT', (string) self::READ_BATCH_SIZE,
                'BLOCK', (string) $blockMs,
                'STREAMS', $this->prefixedStream($connection), $lastId,
            ]));
        } else {
            $response = $connection->xRead(
                [BroadcastEventHistoryRedisStream::STREAM => $lastId],
                self::READ_BATCH_SIZE,
                $blockMs
            );

            // The response is keyed by the requested stream name — with the
            // connection's key prefix applied — so don't match it by name.
            $entries = is_array($response) && $response !== []
                ? (array) reset($response)
                : [];
        }

        $events = [];

        foreach ($entries as $id => $fields) {
            $events[] = BroadcastingEvent::fromStreamEntry((string) $id, $fields);
        }

        return $events;
    }

    protected function prefixedStream(PredisConnection $connection): string
    {
        $prefix = $connection->client()->getOptions()->prefix;

        return ($prefix === null ? '' : $prefix->getPrefix()).BroadcastEventHistoryRedisStream::STREAM;
    }

    /**
     * An SSE comment keeps intermediaries from timing the connection out and
     * lets a broken client connection surface as an aborted write.
     */
    protected function sendHeartbeat(): void
    {
        echo ': keep-alive'.PHP_EOL.PHP_EOL;

        if (ob_get_level() !== 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function normalizePredisEntries(mixed $response): array
    {
        if (! is_array($response)) {
            return [];
        }

        $entries = [];

        // Single requested stream; the reported name carries the
        // connection's key prefix, so don't match it by name.
        foreach ($response as [$stream, $streamEntries]) {
            foreach ($streamEntries as [$id, $fields]) {
                $pairs = [];

                for ($i = 0, $count = count($fields); $i < $count; $i += 2) {
                    $pairs[$fields[$i]] = $fields[$i + 1];
                }

                $entries[$id] = $pairs;
            }
        }

        return $entries;
    }
}
