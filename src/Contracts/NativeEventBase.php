<?php declare(strict_types=1);

namespace Native\Mobile\Contracts;

/**
 * Base class for native events providing envelope storage.
 *
 * This abstract class implements the common getEnvelope() method
 * so concrete event classes only need to implement the factory method.
 * Child classes should store the envelope via constructor promotion.
 */
abstract class NativeEventBase implements NativeEvent
{
    /**
     * @param NativeEventEnvelope $envelope The envelope this event was created from
     */
    public function __construct(
        protected NativeEventEnvelope $envelope
    ) {
    }

    /**
     * Get the envelope associated with this event.
     */
    public function getEnvelope(): NativeEventEnvelope
    {
        return $this->envelope;
    }
}
