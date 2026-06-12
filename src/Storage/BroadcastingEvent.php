<?php

namespace Qruto\Wave\Storage;

use Qruto\Wave\Sse\ServerSentEvent;

// TODO: make readonly after update minimum required PHP version
class BroadcastingEvent
{
    public function __construct(
        public string $channel,
        public string $name,
        public string|array $data,
        public ?string $id,
        public ?string $socket,
    ) {}

    public function send(): void
    {
        (new ServerSentEvent(
            sprintf('%s.%s', $this->channel, $this->name),
            is_array($this->data) ? json_encode($this->data, JSON_THROW_ON_ERROR) : $this->data,
            $this->id,
            config('wave.retry'),
        ))();
    }

    /**
     * Build an event from a raw Redis stream entry as stored by pushEvent().
     *
     * @param  array<string, string>  $fields
     */
    public static function fromStreamEntry(string $id, array $fields): self
    {
        return new self(
            channel: $fields['channel'],
            name: $fields['name'],
            data: json_decode($fields['data'], true, 512, JSON_THROW_ON_ERROR),
            id: $id,
            socket: ($fields['socket'] ?? '') === '' ? null : $fields['socket'],
        );
    }

    public static function fake(array $attributes = []): self
    {
        return new self(
            channel: $attributes['channel'] ?? fake()->word,
            name: $attributes['event'] ?? fake()->word,
            data: $attributes['data'] ?? ['message' => fake()->sentence],
            id: null,
            socket: $attributes['socket'] ?? fake()->randomNumber(6, true).'.'.fake()->randomNumber(6, true),
        );
    }
}
