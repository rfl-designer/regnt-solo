<?php

use App\Models\Task;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function count(): int
    {
        return Task::inbox()->count();
    }

    #[On('task-created')]
    #[On('task-moved')]
    public function refreshCount(): void
    {
        unset($this->count);
    }
}

?>

<span>
    @if ($this->count > 0)
        <flux:badge size="sm" color="zinc">{{ $this->count }}</flux:badge>
    @endif
</span>
