<?php declare(strict_types=1);

namespace Tests\Fixtures;

use Native\Mobile\Contracts\NativeEvent;
use Native\Mobile\Contracts\NativeEventEnvelope;

final class OtherTestNativeEvent implements NativeEvent
{
    public function __construct(private NativeEventEnvelope $envelope) {}

    public function getEnvelope(): NativeEventEnvelope
    {
        return $this->envelope;
    }

    public static function fromNativeEventEnvelope(
        NativeEventEnvelope $envelope,
        array $payload
    ): static {
        return new self($envelope);
    }
}
