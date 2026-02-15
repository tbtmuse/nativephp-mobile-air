<?php declare(strict_types=1);

namespace Tests\Fixtures;

use Livewire\Component;
use Native\Mobile\Attributes\OnNative;

final class MultiHandlerComponent extends Component
{
    #[OnNative(TestNativeEvent::class)]
    public function handleTestEvent(array $event): void {}

    #[OnNative(TestNativeEvent::class)]
    public function handleTestEventAgain(array $event): void {}

    public function render(): string
    {
        return '<div></div>';
    }
}
