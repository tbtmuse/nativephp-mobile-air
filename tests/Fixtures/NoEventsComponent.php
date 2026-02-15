<?php declare(strict_types=1);

namespace Tests\Fixtures;

use Livewire\Component;

final class NoEventsComponent extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}
