<?php declare(strict_types=1);

namespace Native\Mobile\Contracts;

/**
 * Interface for all events that can be dispatched from native code.
 *
 * Events implementing this interface can be instantiated from a
 * NativeEventEnvelope, enabling type-safe deserialization of
 * native-to-PHP event communication.
 */
interface NativeEvent
{
    /**
     * Factory method to create event instance from envelope.
     *
     * This method extracts event-specific data from the payload
     * while accessing routing/metadata via the envelope.
     *
     * @param NativeEventEnvelope $envelope The complete envelope with routing info
     * @param array<string, mixed> $payload Event-specific business data from envelope
     * @return static Event instance ready for dispatch
     */
    public static function fromNativeEventEnvelope(NativeEventEnvelope $envelope, array $payload): static;

    /**
     * Get the envelope associated with this event.
     *
     * Provides access to routing information (source, source_id)
     * and metadata about the native dispatch.
     *
     * @return NativeEventEnvelope The envelope this event was created from
     */
    public function getEnvelope(): NativeEventEnvelope;
}
