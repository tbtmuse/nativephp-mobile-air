<?php declare(strict_types=1);

namespace Tests\Fixtures;

use Livewire\Component;
use Native\Mobile\Attributes\OnNative;

final class CoalescingEventComponent extends Component
{
    public string $test = '';

    #[OnNative(TestCoalescingEvent::class)]
    public function handleCoalescingEvent(array $event): void
    {
        $this->test = 'coalesced';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
