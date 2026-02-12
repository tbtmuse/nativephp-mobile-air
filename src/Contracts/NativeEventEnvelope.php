<?php declare(strict_types=1);

namespace Native\Mobile\Contracts;

use Illuminate\Support\Str;

/**
 * Canonical envelope for all native→PHP events.
 *
 * This value object enforces strict field ordering and type safety
 * for events dispatched from native code (Kotlin/Swift) to PHP.
 *
 * Wire format (strict field order):
 * {
 *   "name": "Native\\Mobile\\Events\\Permissions\\PermissionResult",
 *   "source": "geolocation",
 *   "source_id": "req_abc123",
 *   "dispatch_id": "abc123",
 *   "sent_at": 1770673728,
 *   "payload": { ... },
 *   "meta": {}
 * }
 */
final readonly class NativeEventEnvelope
{
    /**
     * @param string $name Fully-qualified PHP event class name
     * @param string $source Plugin/domain identifier (e.g., "geolocation", "lifecycle")
     * @param string $sourceId Correlation token for this request/action
     * @param string $dispatchId Unique ID for this specific dispatch
     * @param int $sentAt Unix timestamp in milliseconds
     * @param array $payload Event-specific business data
     * @param array $meta Cross-cutting metadata (optional)
     */
    public function __construct(
        public string $name,
        public string $source,
        public string $sourceId,
        public string $dispatchId,
        public int $sentAt,
        public array $payload = [],
        public array $meta = []
    ) {
    }

    /**
     * Create envelope from array (e.g., from HTTP request).
     *
     * Automatically converts camelCase keys to snake_case for idiomatic PHP.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException If required fields are missing or empty
     */
    public static function fromArray(array $data): self
    {
        $required = ['name', 'source', 'source_id', 'dispatch_id', 'sent_at'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                throw new \InvalidArgumentException("Missing required envelope field: {$field}");
            }
        }

        return new self(
            name: $data['name'],
            source: $data['source'],
            sourceId: $data['source_id'],
            dispatchId: $data['dispatch_id'],
            sentAt: (int) $data['sent_at'],
            payload: self::convertKeysToSnakeCase($data['payload'] ?? []),
            meta: self::convertKeysToSnakeCase($data['meta'] ?? [])
        );
    }

    /**
     * Recursively convert camelCase array keys to snake_case.
     *
     * Native code (Kotlin/Swift) uses camelCase, PHP uses snake_case for array keys.
     * This ensures PHP event classes can use idiomatic snake_case.
     *
     * Handles both associative arrays (convert keys) and lists (recurse into items).
     *
     * @param mixed $value Array to convert, or any other value to pass through
     * @return mixed Array with snake_case keys, or original value if not array
     */
    private static function convertKeysToSnakeCase(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // List: recurse into each item (so nested associative arrays get converted too)
        if (array_is_list($value)) {
            return array_map([self::class, 'convertKeysToSnakeCase'], $value);
        }

        // Assoc: snake the keys, recurse into values
        $result = [];
        foreach ($value as $key => $val) {
            $result[Str::snake((string) $key)] = self::convertKeysToSnakeCase($val);
        }

        return $result;
    }

    /**
     * Convert envelope to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'dispatch_id' => $this->dispatchId,
            'sent_at' => $this->sentAt,
            'payload' => $this->payload,
            'meta' => $this->meta,
        ];
    }
}
