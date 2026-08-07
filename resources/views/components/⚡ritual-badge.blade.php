<?php

use App\Models\MorningRitual;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The sidebar's passive nudge (issue #147): a dot next to "Ritual" while
 * today's ritual hasn't been concluded, and nothing at all once it has.
 *
 * Deliberately passive — no count, no colour that reads as an alarm, no
 * blocking. The ritual is a habit, not a deadline, and a badge that shouts
 * would make skipping a day feel like a failure state.
 */
new class extends Component
{
    #[Computed]
    public function pending(): bool
    {
        return ! MorningRitual::completedToday();
    }

    #[On('ritual-completed')]
    public function refreshBadge(): void
    {
        unset($this->pending);
    }
}

?>

<span>
    @if ($this->pending)
        <flux:badge size="sm" color="amber" data-test="ritual-pending-badge">hoje</flux:badge>
    @endif
</span>
