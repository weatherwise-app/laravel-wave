<?php

namespace Qruto\Wave;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Laravel\Octane\Events\RequestTerminated;
use Qruto\Wave\Console\Commands\BroadcastingInstallCommand;
use Qruto\Wave\Console\Commands\ConfigPublishCommand;
use Qruto\Wave\Console\Commands\ServeCommand;
use Qruto\Wave\Console\Commands\SsePingCommand;
use Qruto\Wave\Events\SseConnectionClosedEvent;
use Qruto\Wave\Listeners\RemoveStoredConnectionListener;
use Qruto\Wave\Storage\BroadcastEventHistory;
use Qruto\Wave\Storage\BroadcastEventHistoryRedisStream;
use Qruto\Wave\Storage\PresenceChannelUsersRedisRepository;
use Qruto\Wave\Storage\PresenceChannelUsersRepository;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class WaveServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-wave')
            ->hasConfigFile()
            ->hasRoute('routes')
            ->hasCommand(SsePingCommand::class)
            ->hasCommand(ServeCommand::class);

        if (laravel11OrHigher()) {
            $package
                ->hasCommand(ConfigPublishCommand::class)
                ->hasCommand(BroadcastingInstallCommand::class);
        }
    }

    public function registeringPackage()
    {
        $redisConnectionName = config('broadcasting.connections.redis.connection', 'default');

        // A dedicated connection for SSE delivery. Blocking commands
        // (XREAD BLOCK / PSUBSCRIBE) manage their own timing, so socket read
        // timeouts must not apply — configured here per connection instead of
        // mutating the process-wide `default_socket_timeout`.
        config()->set(
            'database.redis.'.subscriptionConnectionName(),
            array_merge((array) config("database.redis.$redisConnectionName"), [
                'read_timeout' => -1,
                'read_write_timeout' => -1,
            ])
        );

        $this->app->bind(BroadcastEventHistory::class, BroadcastEventHistoryRedisStream::class);
        $this->app->bind(PresenceChannelEvent::class, PresenceChannelEventHandler::class);

        $this->app->extend(BroadcastManager::class, fn ($service, $app) => new BroadcastManagerExtended($app));

        $this->app->bind(
            ServerSentEventSubscriber::class,
            fn ($app) => $app->make(
                config('wave.subscriber', 'stream') === 'pubsub'
                    ? RedisSubscriber::class
                    : RedisStreamSubscriber::class
            )
        );

        $this->app->bind(PresenceChannelUsersRepository::class, PresenceChannelUsersRedisRepository::class);
    }

    public function bootingPackage()
    {
        Event::listen(
            SseConnectionClosedEvent::class,
            [RemoveStoredConnectionListener::class, 'handle']
        );

        // Octane workers serve many requests from one process; make sure a
        // subscription connection from an uncleanly ended stream can never
        // leak into the next request handled by the worker.
        if (class_exists(RequestTerminated::class)) {
            Event::listen(
                RequestTerminated::class,
                fn () => Redis::purge(subscriptionConnectionName())
            );
        }
    }
}
