<?php declare(strict_types=1);

namespace Tests\Fixtures;

use Native\Mobile\Contracts\Coalesces;
use Native\Mobile\Contracts\NativeEvent;
use Native\Mobile\Contracts\NativeEventEnvelope;

final class TestCoalescingEvent implements NativeEvent, Coalesces
{
    public function __construct(
        private string $key,
        public array $data,
        private NativeEventEnvelope $envelope
    ) {}

    public function coalesceKey(): string
    {
        return $this->key;
    }

    public function coalesceWith(Coalesces $other): self
    {
        return new self(
            $this->key,
            array_merge($this->data, $other->data),
            $this->envelope
        );
    }

    public function getEnvelope(): NativeEventEnvelope
    {
        return $this->envelope;
    }

    public static function fromNativeEventEnvelope(
        NativeEventEnvelope $envelope,
        array $payload
    ): static {
        return new self('test-key', $payload, $envelope);
    }
}
