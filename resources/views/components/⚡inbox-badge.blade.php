<?php

use App\Enums\ActivityType;
use App\Models\Activity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function count(): int
    {
        return Activity::query()
            ->whereIn('type', [ActivityType::Issue, ActivityType::Task])
            ->inbox()
            ->count();
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
