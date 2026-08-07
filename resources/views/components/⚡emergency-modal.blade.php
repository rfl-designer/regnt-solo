<?php

use App\Enums\ServiceClass;
use App\Exceptions\SingleActiveEmergencyException;
use App\Models\Activity;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $taskId = null;

    public string $reason = '';

    /**
     * Which of the two blocking questions is on screen: 'conflict' asks
     * whether to keep or replace the Emergência that already holds the
     * board's single slot; 'reason' collects the mandatory motivo.
     */
    public string $step = 'reason';

    /**
     * The Emergência currently holding the slot, as
     * {@see SingleActiveEmergencyException::activeEmergencyContext()}.
     *
     * @var array{id: int, title: string, reason: string|null, age_in_days: int}|null
     */
    public ?array $conflict = null;

    /**
     * The single UI checkpoint for classifying something as Emergência
     * (issue #143). Every surface that offers the classification — Inbox,
     * Command Palette, Quick-Add — defers here instead of writing straight
     * away, because the domain guards refuse an Emergência with no motivo
     * and refuse a second active one. Collecting both answers up front is
     * what turns those refusals into a decision the user actually makes.
     *
     * The Task Modal is the deliberate exception: it already owns an open
     * modal, so it asks the same two questions inline rather than stacking
     * a second one on top.
     */
    #[On('open-emergency-modal')]
    public function open(int $taskId): void
    {
        $task = Activity::findOrFail($taskId);

        $this->taskId = $taskId;
        $this->reason = $task->emergency_reason ?? '';
        $this->conflict = null;
        $this->step = 'reason';

        $active = Activity::query()
            ->activeEmergency()
            ->whereKeyNot($taskId)
            ->orderBy('emergency_since')
            ->first();

        if ($active !== null) {
            $this->conflict = (new SingleActiveEmergencyException($active))->activeEmergencyContext();
            $this->step = 'conflict';
        }

        $this->resetValidation();
        $this->showModal = true;
    }

    /**
     * Keep the Emergência that already holds the slot: the classification
     * the user started simply doesn't happen.
     */
    public function keepCurrent(): void
    {
        $title = $this->conflict['title'] ?? '';

        $this->close();

        Flux::toast(variant: 'warning', heading: 'Emergência mantida', text: $title);
    }

    /**
     * Choose to replace the current Emergência — which still needs a motivo
     * for the new one before anything is written.
     */
    public function replaceCurrent(): void
    {
        $this->step = 'reason';
    }

    public function confirm(): void
    {
        $this->validate([
            'reason' => 'required|string|max:1000',
        ], [
            'reason.required' => 'Informe por que isso é uma Emergência.',
        ]);

        $task = Activity::findOrFail($this->taskId);
        $conflictId = $this->conflict['id'] ?? null;

        try {
            DB::transaction(function () use ($task, $conflictId): void {
                // Chained on purpose: the demotion is a real save that must
                // succeed before the promotion is even attempted, and both
                // share a transaction so a refused promotion never leaves
                // the board with zero Emergências.
                if ($conflictId !== null) {
                    Activity::findOrFail($conflictId)->update([
                        'service_class' => ServiceClass::Standard,
                    ]);
                }

                $task->update([
                    'service_class' => ServiceClass::Emergency,
                    'emergency_reason' => $this->reason,
                ]);
            });
        } catch (SingleActiveEmergencyException $e) {
            // Someone else took the slot between opening this modal and
            // confirming: ask the same question again with fresh data.
            $this->conflict = $e->activeEmergencyContext();
            $this->step = 'conflict';

            return;
        }

        $this->close();

        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Emergência classificada', text: $task->title);
    }

    public function cancel(): void
    {
        $this->close();
    }

    private function close(): void
    {
        $this->showModal = false;
        $this->reset('taskId', 'reason', 'step', 'conflict');
        $this->resetValidation();
    }
};

?>

<div>
    <flux:modal wire:model.self="showModal" class="md:w-[28rem]">
        @if ($step === 'conflict')
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Já existe uma Emergência ativa</flux:heading>
                    <flux:text class="mt-2">
                        Só pode haver uma Emergência no board. Escolha qual das duas é a Emergência agora.
                    </flux:text>
                </div>

                <div class="rounded-lg border border-red-500/20 bg-zinc-800/50 p-3">
                    <div class="flex items-start gap-2">
                        <flux:icon name="fire" class="mt-0.5 size-4 shrink-0 text-red-400" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-zinc-200">{{ $conflict['title'] }}</p>
                            @if ($conflict['reason'])
                                <p class="mt-1 text-xs text-zinc-400">{{ $conflict['reason'] }}</p>
                            @endif
                            <p class="mt-1 text-xs text-zinc-500">
                                Emergência há {{ $conflict['age_in_days'] }} {{ $conflict['age_in_days'] === 1 ? 'dia' : 'dias' }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="keepCurrent" variant="ghost">
                        Manter a atual
                    </flux:button>
                    <flux:button wire:click="replaceCurrent" variant="danger">
                        Substituir
                    </flux:button>
                </div>
            </div>
        @else
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Por que isso é uma Emergência?</flux:heading>
                    <flux:text class="mt-2">
                        A Emergência fura o limite de Fazendo, então o motivo fica registrado no card.
                    </flux:text>
                </div>

                @if ($conflict)
                    <flux:callout variant="warning" icon="arrow-path" heading="{{ $conflict['title'] }} volta para Padrão" />
                @endif

                <flux:textarea
                    wire:model="reason"
                    label="Motivo"
                    placeholder="Ex: produção fora do ar, cliente parado, prazo legal hoje..."
                    rows="3"
                    autofocus
                />

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="cancel" variant="ghost">
                        Cancelar
                    </flux:button>
                    <flux:button wire:click="confirm" variant="danger">
                        Classificar como Emergência
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
