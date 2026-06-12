<?php

namespace Qruto\Wave\Tests\Support;

use Qruto\Wave\RedisStreamSubscriber;

/**
 * The production subscriber loops until the client disconnects or the
 * configured lifetime ends. Tests need the stream to terminate after a
 * known number of read passes, mirroring how the legacy pub/sub mock
 * replays and returns.
 */
class OneShotStreamSubscriber extends RedisStreamSubscriber
{
    protected int $iterations = 0;

    protected function shouldContinue(int $startedAt): bool
    {
        return $this->iterations++ < (int) config('wave.test_loop_iterations', 1);
    }
}
