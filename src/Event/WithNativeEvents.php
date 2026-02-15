<?php declare(strict_types=1);

namespace Native\Mobile\Event;

use Livewire\Component;

/**
 * @mixin Component
 */
trait WithNativeEvents
{
    public function mountWithNativeEvents(NativeEventSubscriptions $subscriptions): void
    {
        $subscriptions->register($this);
    }
}
