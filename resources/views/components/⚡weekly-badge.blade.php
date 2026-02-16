<?php

use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function weekNumber(): string
    {
        return 'S' . str_pad((string) Carbon::now()->weekOfYear, 2, '0', STR_PAD_LEFT);
    }
}

?>

<span>
    <flux:badge size="sm" color="zinc">{{ $this->weekNumber }}</flux:badge>
</span>
