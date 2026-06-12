<?php

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;
use Qruto\Wave\RedisStreamSubscriber;
use Qruto\Wave\Storage\BroadcastEventHistory;
use Qruto\Wave\Storage\BroadcastingEvent;

/**
 * Runs against a real Redis server (REDIS_HOST / REDIS_PORT) with both
 * supported clients. The unit suite uses an in-process mock that applies
 * no key prefix, so client- and prefix-specific behaviour is only
 * verifiable here.
 */
function integrationRedisManager(string $client): RedisManager
{
    return new RedisManager(app(), $client, [
        'options' => [
            // Deliberately prefixed: XREAD responses are keyed by the
            // prefixed stream name and Predis needs explicit prefixing
            // for raw XREAD commands.
            'prefix' => 'wave_integration_',
        ],
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 15,
            'timeout' => 1.0,
        ],
    ]);
}

it('reads events and ids from a real Redis stream', function (string $client) {
    try {
        $manager = integrationRedisManager($client);
        $connection = $manager->connection();
        $connection->ping();
    } catch (Throwable $e) {
        $this->markTestSkipped("Redis is not available for {$client}: {$e->getMessage()}");
    }

    // swap() updates the facade's cached root as well as the container
    // binding — the mock from the global beforeEach is already cached.
    Redis::swap($manager);
    config()->set('wave.stream_read_timeout', 1);

    $connection->flushdb();

    try {
        $history = app(BroadcastEventHistory::class);

        $first = BroadcastingEvent::fake(['channel' => 'demo', 'event' => 'first']);
        $second = BroadcastingEvent::fake(['channel' => 'demo', 'event' => 'second']);

        $history->pushEvent($first);
        $history->pushEvent($second);

        $probe = new class extends RedisStreamSubscriber
        {
            public function read($connection, string $lastId): array
            {
                return $this->readNewEvents($connection, $lastId);
            }

            public function latest($connection): string
            {
                return $this->latestEventId($connection);
            }
        };

        expect($history->latestEventId())->toBe($second->id)
            ->and($probe->latest($connection))->toBe($second->id);

        $events = $probe->read($connection, '0-0');

        expect($events)->toHaveCount(2)
            ->and($events[0]->id)->toBe($first->id)
            ->and($events[0]->channel)->toBe('demo')
            ->and($events[0]->name)->toBe('first')
            ->and($events[0]->data)->toBe($first->data)
            ->and($events[1]->id)->toBe($second->id)
            ->and($events[1]->name)->toBe('second');

        $events = $probe->read($connection, $first->id);

        expect($events)->toHaveCount(1)
            ->and($events[0]->id)->toBe($second->id);

        // A blocking read past the newest entry waits out the timeout
        // and returns nothing.
        expect($probe->read($connection, $second->id))->toBe([]);
    } finally {
        $connection->flushdb();
    }
})->with(['phpredis', 'predis']);
