<?php

use App\Services\ClientUpdateService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Quantos updates estão devidos agora (issue #149).
 *
 * Diferente da badge do ritual, esta conta: "3" e "1" são situações
 * diferentes quando o que está do outro lado são clientes esperando
 * notícias. Some sozinha quando não há nenhum devido.
 */
new class extends Component
{
    #[Computed]
    public function due(): int
    {
        return app(ClientUpdateService::class)->dueCount();
    }

    #[On('client-update-sent')]
    public function refreshBadge(): void
    {
        unset($this->due);
    }
}

?>

{{-- Como a badge do ritual: numa SPA aberta atravessando a virada do dia,
     nada recalcularia a urgência até um envio acontecer, e a sidebar
     continuaria mostrando a fila de ontem. --}}
<span wire:poll.300s="refreshBadge">
    @if ($this->due > 0)
        <flux:badge size="sm" color="amber" data-test="updates-due-badge">{{ $this->due }}</flux:badge>
    @endif
</span>
