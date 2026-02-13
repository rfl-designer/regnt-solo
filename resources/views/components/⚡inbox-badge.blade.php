<?php

use App\Models\Task;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    #[On('task-created')]
    #[On('task-moved')]
    public function count(): int
    {
        return Task::inbox()->count();
    }
}

?>

<span>
    @if ($this->count > 0)
        <flux:badge size="sm" color="zinc">{{ $this->count }}</flux:badge>
    @endif
</span>
