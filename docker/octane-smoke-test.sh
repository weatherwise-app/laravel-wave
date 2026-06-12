#!/usr/bin/env bash
#
# End-to-end smoke test: serves a skeleton Laravel app with this package
# through Laravel Octane on FrankenPHP with a SINGLE worker, then verifies
# the three behaviours that worker-based runtimes break with the legacy
# pub/sub subscriber:
#
#   1. events are delivered live, heartbeats flow, and the connection is
#      closed by the server when wave.max_connection_lifetime elapses;
#   2. a second connection on the same (only) worker resumes from
#      Last-Event-Id without losing events fired while disconnected;
#   3. a client that disappears mid-stream frees the worker within the
#      heartbeat interval, not the connection lifetime.
#
# Requires Docker. Takes a few minutes on first run (composer + FrankenPHP
# download). Exits non-zero on the first failed assertion.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IMAGE=wave-smoke-php:8.4
NET=wave-smoke
REDIS=wave-smoke-redis
APP=wave-smoke-app

cleanup() {
    docker rm -f "$REDIS" "$APP" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "==> Building PHP image"
docker build -q -t "$IMAGE" -f "$ROOT/docker/php.Dockerfile" "$ROOT/docker" >/dev/null

echo "==> Starting Redis and app containers"
cleanup # clear leftovers from a previous run that died without the trap
docker network create "$NET" >/dev/null
docker run -d --name "$REDIS" --network "$NET" redis:7-alpine >/dev/null
docker run -d --name "$APP" --network "$NET" -v "$ROOT":/package "$IMAGE" sleep infinity >/dev/null

echo "==> Creating skeleton app with the local package (this takes a while)"
docker exec "$APP" composer create-project laravel/laravel /srv/app \
    --no-interaction --no-progress --quiet

docker exec -i -w /srv/app "$APP" sh -s <<SETUP
set -e
composer config repositories.wave path /package
composer config minimum-stability dev
composer require "qruto/laravel-wave:*" laravel/octane --no-interaction --no-progress --quiet
php artisan octane:install --server=frankenphp --no-interaction >/dev/null
php artisan config:publish broadcasting >/dev/null

mkdir -p app/Events
cat > app/Events/SmokeEvent.php <<'EOF'
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class SmokeEvent implements ShouldBroadcastNow
{
    public function __construct(public int \$n) {}

    public function broadcastOn(): Channel
    {
        return new Channel('demo');
    }

    public function broadcastAs(): string
    {
        return 'smoke';
    }

    public function broadcastWith(): array
    {
        return ['n' => \$this->n];
    }
}
EOF

cat >> routes/web.php <<'EOF'

use App\Events\SmokeEvent;

Route::get('/fire/{n}', function (int \$n) {
    broadcast(new SmokeEvent(\$n));

    return response()->json(['fired' => \$n]);
});
EOF

sed -i \
    -e "s/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=redis/" \
    -e "s/^SESSION_DRIVER=.*/SESSION_DRIVER=file/" \
    -e "s/^CACHE_STORE=.*/CACHE_STORE=file/" \
    -e "s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/" \
    -e "s/^REDIS_HOST=.*/REDIS_HOST=wave-smoke-redis/" \
    .env
grep -q "^BROADCAST_CONNECTION=" .env || echo "BROADCAST_CONNECTION=redis" >> .env
grep -q "^REDIS_HOST=" .env || echo "REDIS_HOST=wave-smoke-redis" >> .env
{
    echo "REDIS_CLIENT=phpredis"
    echo "OCTANE_SERVER=frankenphp"
    echo "WAVE_STREAM_READ_TIMEOUT=2"
    echo "WAVE_MAX_CONNECTION_LIFETIME=8"
} >> .env
php artisan config:clear >/dev/null
SETUP

echo "==> Starting Octane (FrankenPHP, 1 worker)"
docker exec -d -w /srv/app "$APP" sh -c \
    'php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=1 > /tmp/octane.log 2>&1'
sleep 5

echo "==> Running scenarios"
docker exec -i -w /srv/app "$APP" sh -s <<'SCENARIOS'
set -e

fail() { echo "FAIL: $1"; exit 1; }
ok() { echo "  ok: $1"; }

curl -sf --max-time 5 http://localhost:8000/fire/1 >/dev/null \
    || fail "octane did not come up (see octane.log)"

# --- Scenario 1: live delivery, heartbeats, bounded lifetime -------------
START=$(date +%s)
curl -sN --max-time 15 http://localhost:8000/wave > /tmp/sse1.txt 2>&1 &
SSE_PID=$!
sleep 2
php artisan tinker --execute="broadcast(new App\Events\SmokeEvent(41));" >/dev/null 2>&1
wait $SSE_PID || fail "sse1 curl did not exit cleanly"
DURATION=$(( $(date +%s) - START ))

grep -q "general.connected" /tmp/sse1.txt || fail "sse1: no connected event"
grep -q '"n":41' /tmp/sse1.txt || fail "sse1: smoke event 41 not delivered"
grep -q ": keep-alive" /tmp/sse1.txt || fail "sse1: no heartbeats"
[ "$DURATION" -ge 6 ] && [ "$DURATION" -le 12 ] \
    || fail "sse1: expected ~8s lifetime close, took ${DURATION}s"
ok "live delivery + heartbeats + lifetime close (${DURATION}s)"

# --- Scenario 2: same worker reconnect, resume without event loss --------
LAST_ID=$(grep -A2 '"n":41' /tmp/sse1.txt | sed -n 's/^id: //p' | head -1)
[ -n "$LAST_ID" ] || fail "sse1: could not extract event id"

php artisan tinker --execute="broadcast(new App\Events\SmokeEvent(42));" >/dev/null 2>&1

curl -sN --max-time 15 -H "Last-Event-Id: $LAST_ID" http://localhost:8000/wave > /tmp/sse2.txt 2>&1 &
SSE_PID=$!
sleep 2
php artisan tinker --execute="broadcast(new App\Events\SmokeEvent(43));" >/dev/null 2>&1
wait $SSE_PID || fail "sse2 curl did not exit cleanly"

grep -q '"n":42' /tmp/sse2.txt || fail "sse2: missed event 42 not replayed on resume"
grep -q '"n":43' /tmp/sse2.txt || fail "sse2: live event 43 not delivered after reconnect"
ok "reconnect on same worker + resume from Last-Event-Id"

# --- Scenario 3: aborted client frees the worker quickly ------------------
curl -sN --max-time 3 http://localhost:8000/wave > /tmp/sse3.txt 2>&1 || true
T0=$(date +%s)
curl -s --max-time 10 http://localhost:8000/fire/99 > /tmp/fire.txt
WAITED=$(( $(date +%s) - T0 ))

grep -q '"fired":99' /tmp/fire.txt || fail "post-abort request failed"
[ "$WAITED" -le 4 ] || fail "worker not freed after client abort (waited ${WAITED}s)"
ok "client abort freed the worker in ${WAITED}s"

echo "ALL SMOKE TESTS PASSED"
SCENARIOS
