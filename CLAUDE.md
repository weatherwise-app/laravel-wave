# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

WeatherWise's fork of `qruto/laravel-wave` — Laravel broadcasting over Server-Sent Events using the native `redis` broadcast driver. The fork (branch `octane-compat`) exists to make SSE delivery safe on long-lived runtimes (Laravel Octane / FrankenPHP) and is consumed by `weatherwise-app/app-server` via a composer VCS repository as `dev-octane-compat`. Requires PHP ^8.2 and Laravel ^12|^13. Breaking changes against upstream are tracked in CHANGELOG.md under "Unreleased".

## Commands

```bash
composer test            # pint --test + rector --dry-run + pest, in that order
composer test:unit       # pest only
composer test:types      # phpstan (level 5, larastan with checkOctaneCompatibility)
composer fix             # rector + pint, apply fixes

vendor/bin/pest tests/Feature/EventsTest.php          # single file
vendor/bin/pest --filter 'resumes after reconnection' # single test
```

All four gates (pest, pint, phpstan, rector) must pass — CI enforces each in a separate workflow, with tests across a PHP 8.2–8.4 × Laravel 12/13 × prefer-lowest/stable matrix.

Without a local PHP, use the docker environment:

```bash
docker build -t wave-test-php:8.4 -f docker/php.Dockerfile docker
docker network create wave-test
docker run -d --name wave-test-redis --network wave-test redis:7-alpine
docker run --rm -v "$PWD":/app -w /app --network wave-test \
    -e REDIS_HOST=wave-test-redis wave-test-php:8.4 composer test:unit

./docker/octane-smoke-test.sh   # full Octane/FrankenPHP end-to-end check (~4 min)
```

Integration tests (`tests/Integration/`) run against a real Redis with both phpredis and predis and skip silently when `REDIS_HOST` is unreachable — a plain `pest` run without Redis gives false confidence about client-specific behavior.

## Architecture

The Redis stream `broadcasted_events` is the heart of everything (name lives in `BroadcastEventHistoryRedisStream::STREAM`):

1. **Write path** — `WaveServiceProvider` swaps Laravel's `BroadcastManager` for `BroadcastManagerExtended`, whose redis driver pushes every broadcast event into the stream (via `BroadcastEventHistory`) *before* publishing to pub/sub. Stream entry ids become SSE event ids, which is what makes `Last-Event-Id` resume work. Pings (`SsePingCommand`), whispers (`SendWhisper`), and presence join/leave events all flow through this same path.

2. **Read path** — `ServerSentEventStream` (a `Responsable`) handles `GET /wave`: it resolves the starting stream position *at request time* (client's validated `Last-Event-Id`, else newest id — events arriving during stream startup must not be skipped), replays missed events, emits a `connected` marker, then hands off to a `ServerSentEventSubscriber` for live delivery.

3. **Two subscribers**, selected by `wave.subscriber` config:
   - `RedisStreamSubscriber` (default) — blocking `XREAD` loop on the stream. Heartbeat comments on read timeout detect dead clients; optional `wave.max_connection_lifetime` closes connections on schedule (the block time is clamped to the remaining lifetime); cleanup is in-band `try/finally`. This is the Octane-safe path.
   - `RedisSubscriber` (legacy) — `PSUBSCRIBE`, holds the connection in subscribe mode for the whole request. PHP-FPM only.

4. **Connection lifecycle** — both subscribers use a dedicated `<connection>-subscription` Redis connection (config cloned in the provider with infinite read timeouts, scoped per-connection instead of `ini_set`). In `finally`: disconnect and `Redis::purge()` **before** firing `SseConnectionClosedEvent` — the event's listener touches Redis and can throw; a leaked subscribe-mode connection poisons the next request on a long-lived worker. An Octane `RequestTerminated` listener purges defensively as well.

5. **Presence** — `PresenceChannelUsersRedisRepository` tracks members per connection id (socket); `SseConnectionClosedEvent` → `RemoveStoredConnectionListener` broadcasts leave events when a user's last connection closes.

## Hard-won gotchas

- **Redis key prefixes**: `XREAD` responses are keyed by the *prefixed* stream name — never match the response by the bare stream name. Predis' native `xread` does not apply the configured prefix at all (its other stream commands do), hence the raw `XREAD` against an explicitly prefixed key in `RedisStreamSubscriber`. The unit-test mock applies **no prefix**, so prefix bugs only surface in the integration tests or the smoke test.
- **The test harness** (`tests/Pest.php`): the whole `redis` service is replaced by `RedisConnectionMock` (faithful real-Redis semantics for `xRange`/`xRevRange`/`xRead`, including inverted-bounds behavior — keep it that way), and the subscriber is bound to `LimitedIterationsStreamSubscriber` so streams terminate (`wave.test_loop_iterations`, default 1). Streamed responses execute lazily — the stream callback runs when the test calls `streamedContent()`, *after* the test has fired events.
- **Swapping Redis in tests**: use `Redis::swap($manager)`, not `$this->instance('redis', ...)` — the facade caches its resolved root and will keep serving the mock.
- **XREVRANGE argument order** is `(key, end, start)` — newest bound first. Passing `('-', '+')` returns an empty set on real Redis (this bug shipped upstream for years because the old mock accepted it).
- `routes/channels.php`-style boot code connects to Redis when `Broadcast::channel()` is first registered — `artisan package:discover` in a consuming app fails without a reachable Redis.

## Conventions

- Tests are Pest; feature tests drive the full HTTP stream via the `waveConnection()` helper in `tests/Pest.php` and assert on parsed SSE frames (heartbeat comment frames are filtered by the parser).
- `phpstan-baseline.neon` exists but should shrink, not grow; `reportUnmatchedIgnoredErrors` is effectively enforced — remove stale entries when refactoring.
- Public-facing docs live in README.md ("Deploying with Laravel Octane" and "Persistent Connection with Nginx + PHP FPM" cover runtime tuning); keep the embedded config snippet in "Server Options" in sync with `config/wave.php`.
