<?php declare(strict_types=1);

namespace Tests\Fixtures;

use Livewire\Component;
use Native\Mobile\Attributes\OnNative;

final class TestEventComponent extends Component
{
    public string $test = '';

    #[OnNative(TestNativeEvent::class)]
    public function handleTestEvent(array $event): void
    {
        $this->test = 'handled';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
