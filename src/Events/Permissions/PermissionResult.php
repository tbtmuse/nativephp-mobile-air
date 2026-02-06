<?php declare(strict_types=1);

namespace Native\Mobile\Events\Permissions;

use InvalidArgumentException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Unified permission result event.
 *
 * Infrastructure event for all permission outcomes across all plugins.
 * Domain-specific events (PhotoTaken, LocationUpdated, etc.) remain separate.
 *
 * @example Camera grants permission:
 *   new PermissionResult(
 *       source: 'camera',
 *       sourceId: 'req_abc123',
 *       permission: 'android.permission.CAMERA',
 *       status: PermissionStatus::GRANTED,
 *       meta: ['action' => 'photo']
 *   )
 *
 * @example Geolocation grants permission:
 *   new PermissionResult(
 *       source: 'geolocation',
 *       sourceId: 'req_def456',
 *       permission: 'android.permission.ACCESS_FINE_LOCATION',
 *       status: PermissionStatus::GRANTED,
 *       meta: ['fine' => 'granted', 'coarse' => 'granted']
 *   )
 */
final readonly class PermissionResult
{
    use Dispatchable;
    use SerializesModels;

    public PermissionStatus $status;

    /**
     * @param string $source Plugin identifier (e.g., 'camera', 'geolocation', 'firebase')
     * @param string $sourceId Unique correlation token (e.g., 'req_abc123')
     * @param string $permission Platform permission string (e.g., 'android.permission.CAMERA')
     * @param string $status Permission status
     * @param array $meta Optional domain-specific extras (e.g., ['action' => 'photo'])
     */
    public function __construct(
        public string $source,
        public string $sourceId,
        public string $permission,
        string $status,
        public array $meta = []
    ) {
        $this->status = PermissionStatus::tryFrom($status) ?? throw new InvalidArgumentException("Invalid permission status: {$status}");
    }
}
