<?php declare(strict_types=1);

namespace Native\Mobile\Events\Geolocation;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Contracts\Permissions\PermissionResultContract;
use Native\Mobile\Contracts\Permissions\PermissionStatus;

class PermissionRequestResult implements PermissionResultContract
{
    use Dispatchable;

    public function __construct(
        public readonly string $location,
        public readonly string $coarseLocation,
        public readonly string $fineLocation,
        public readonly ?string $error = null,
        public readonly ?string $id = null,
        public readonly string $sourceId = ''
    ) {}

    public function source(): string
    {
        return 'geolocation';
    }

    public function sourceId(): string
    {
        return $this->sourceId;
    }

    public function permission(): string
    {
        return 'android.permission.ACCESS_FINE_LOCATION';
    }

    public function status(): PermissionStatus
    {
        return $this->location === 'granted' ? PermissionStatus::GRANTED : PermissionStatus::DENIED;
    }
}
