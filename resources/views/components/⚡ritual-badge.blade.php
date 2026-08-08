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

{{-- O poll de baixa frequência existe por causa da virada do dia: numa SPA
     mantida aberta, o badge só recalcularia ao montar ou ao receber
     `ritual-completed`, e uma sessão que atravessa a meia-noite continuaria
     mostrando o estado de ontem. Cinco minutos é barato (uma consulta por
     dia de trabalho) e mantém a pergunta honesta. --}}
<span wire:poll.300s="refreshBadge">
    @if ($this->pending)
        <flux:badge size="sm" color="amber" data-test="ritual-pending-badge">hoje</flux:badge>
    @endif
</span>
