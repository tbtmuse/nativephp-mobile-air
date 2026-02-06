<?php declare(strict_types=1);

namespace Native\Mobile\Events\Camera;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Contracts\Permissions\PermissionResultContract;
use Native\Mobile\Contracts\Permissions\PermissionStatus;

/**
 * Camera permission granted event
 *
 * This event is dispatched when camera permission is granted.
 * Implements PermissionResultContract for consistent permission handling.
 */
final class PermissionGranted implements PermissionResultContract
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $action,
        public ?string $id = null,
        public string $sourceId = ''
    ) {}

    public function source(): string
    {
        return 'camera';
    }

    public function sourceId(): string
    {
        return $this->sourceId;
    }

    public function permission(): string
    {
        return 'android.permission.CAMERA';
    }

    public function status(): PermissionStatus
    {
        return PermissionStatus::GRANTED;
    }
}
