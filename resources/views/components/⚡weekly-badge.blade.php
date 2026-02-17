<?php

use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function weekLabel(): string
    {
        return 'Semana ' . Carbon::now()->weekOfYear;
    }
}

?>

<flux:badge size="sm" color="zinc">{{ $this->weekLabel }}</flux:badge>
