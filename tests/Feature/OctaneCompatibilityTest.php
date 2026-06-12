<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Qruto\Wave\Events\SseConnectionClosedEvent;
use Qruto\Wave\RedisStreamSubscriber;
use Qruto\Wave\RedisSubscriber;
use Qruto\Wave\ServerSentEventSubscriber;
use Qruto\Wave\Tests\Support\Events\PublicEvent;
use Qruto\Wave\WaveServiceProvider;

it('does not mutate process-wide ini settings', function () {
    $socketTimeout = ini_get('default_socket_timeout');
    $executionLimit = ini_get('max_execution_time');

    $connection = waveConnection();
    event(new PublicEvent);

    $connection->assertEventReceived(PublicEvent::class);

    expect(ini_get('default_socket_timeout'))->toBe($socketTimeout)
        ->and(ini_get('max_execution_time'))->toBe($executionLimit);
});

it('sends a heartbeat comment when no events arrive within the read timeout', function () {
    // First pass consumes the connection's own "connected" marker; the
    // second pass finds an empty stream and must heartbeat.
    config()->set('wave.test_loop_iterations', 2);

    $connection = waveConnection();

    expect($connection->response->streamedContent())->toContain(': keep-alive');
});

it('fires a connection closed event when the stream ends', function () {
    Event::fake([SseConnectionClosedEvent::class]);

    $connection = waveConnection();
    $connection->response->streamedContent();

    Event::assertDispatched(
        SseConnectionClosedEvent::class,
        fn (SseConnectionClosedEvent $event) => $event->connectionId === $connection->id()
    );
});

it('selects the subscriber implementation from config', function () {
    $this->app->register(WaveServiceProvider::class, force: true);

    config()->set('wave.subscriber', 'pubsub');
    expect(app(ServerSentEventSubscriber::class))->toBeInstanceOf(RedisSubscriber::class);

    config()->set('wave.subscriber', 'stream');
    expect(app(ServerSentEventSubscriber::class))->toBeInstanceOf(RedisStreamSubscriber::class);
});

it('still delivers events through the legacy pub/sub subscriber', function () {
    $this->app->bind(ServerSentEventSubscriber::class, RedisSubscriber::class);

    $connection = waveConnection();
    event(new PublicEvent);

    $connection->assertEventReceived(PublicEvent::class);
});

it('enforces the configured max connection lifetime', function () {
    $subscriber = new class extends RedisStreamSubscriber
    {
        public function continues(int $startedAt): bool
        {
            return $this->shouldContinue($startedAt);
        }
    };

    Carbon::setTestNow('2026-06-12 10:00:00');
    $startedAt = now()->getTimestamp();

    config()->set('wave.max_connection_lifetime', 0);
    Carbon::setTestNow('2026-06-12 11:00:00');
    expect($subscriber->continues($startedAt))->toBeTrue();

    config()->set('wave.max_connection_lifetime', 30);
    Carbon::setTestNow('2026-06-12 10:00:29');
    expect($subscriber->continues($startedAt))->toBeTrue();

    Carbon::setTestNow('2026-06-12 10:00:30');
    expect($subscriber->continues($startedAt))->toBeFalse();
});
