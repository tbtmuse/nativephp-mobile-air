<?php declare(strict_types=1);

namespace Native\Mobile\Events\Permissions;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Contracts\NativeEvent;
use Native\Mobile\Contracts\NativeEventBase;
use Native\Mobile\Contracts\NativeEventEnvelope;

/**
 * Unified permission result event.
 *
 * Infrastructure event for all permission outcomes across all plugins.
 * Contains batch of permission results from a single user action.
 *
 * Access routing information via $event->getEnvelope()->source, ->sourceId
 *
 * @example Batched geolocation permission results:
 *   PermissionResult::fromNativeEventEnvelope($envelope, [
 *       'results' => [
 *           ['permission' => 'android.permission.ACCESS_FINE_LOCATION', 'status' => 'granted'],
 *           ['permission' => 'android.permission.ACCESS_COARSE_LOCATION', 'status' => 'granted'],
 *           ['permission' => 'android.permission.FOREGROUND_SERVICE_LOCATION', 'status' => 'granted'],
 *           ['permission' => 'android.permission.POST_NOTIFICATIONS', 'status' => 'granted']
 *       ]
 *   ])
 */
final class PermissionResult extends NativeEventBase implements NativeEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param NativeEventEnvelope $envelope The envelope with routing info (source, source_id)
     * @param array $results Array of permission results, each with:
     *                       - permission: string (e.g., 'android.permission.CAMERA')
     *                       - status: string ('granted' | 'denied' | 'blocked')
     *                       - meta?: array (optional per-permission details)
     * @param array $meta Optional request-level metadata
     */
    private function __construct(
        NativeEventEnvelope $envelope,
        public array $results,
        public array $meta = []
    ) {
        parent::__construct($envelope);
    }

    /**
     * Factory method to create event from envelope.
     *
     * @param NativeEventEnvelope $envelope The complete envelope with routing info
     * @param array<string, mixed> $payload Event-specific data containing 'results'
     * @throws \InvalidArgumentException If results are missing or invalid
     */
    public static function fromNativeEventEnvelope(NativeEventEnvelope $envelope, array $payload): static
    {
        if (!isset($payload['results']) || !is_array($payload['results'])) {
            throw new \InvalidArgumentException('Payload must contain results array');
        }

        return new self(
            envelope: $envelope,
            results: $payload['results'],
            meta: $payload['meta'] ?? []
        );
    }

    /**
     * Get the source plugin identifier (convenience method).
     *
     * @return string e.g., 'geolocation', 'camera', 'firebase'
     */
    public function getSource(): string
    {
        return $this->envelope->source;
    }

    /**
     * Get the source correlation ID (convenience method).
     *
     * @return string e.g., 'req_abc123'
     */
    public function getSourceId(): string
    {
        return $this->envelope->sourceId;
    }
}
