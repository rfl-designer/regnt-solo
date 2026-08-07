<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\SingleActiveEmergencyException;
use App\Models\Activity;
use App\Services\EmergencySlotService;
use Flux\Flux;
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
     * A board move that was refused because it would light a second
     * Emergência (e.g. dragging a concluded Emergência back onto the
     * board). When set, the pending operation is the *move*, not a
     * classification: the item already carries its motivo, so only the
     * "Manter a atual / Substituir" question is left to answer.
     */
    public ?string $pendingStatus = null;

    /**
     * The single UI checkpoint for classifying something as Emergência
     * (issue #143). Every surface that offers the classification — Inbox,
     * Command Palette, Quick-Add — and every surface whose operation was
     * refused for lighting a second one — the Kanban's drag — defers here
     * instead of writing straight away, because the domain guards refuse an
     * Emergência with no motivo and refuse a second active one. Collecting
     * both answers up front is what turns those refusals into a decision
     * the user actually makes.
     *
     * The Task Modal is the deliberate exception: it already owns an open
     * modal, so it asks the same two questions inline rather than stacking
     * a second one on top.
     *
     * @param  string|null  $status  Set when the pending operation is a board move rather than a classification.
     * @param  string|null  $reason  Pre-filled motivo (e.g. the AI's own justification for suggesting the classification).
     */
    #[On('open-emergency-modal')]
    public function open(int $taskId, ?string $status = null, ?string $reason = null): void
    {
        $task = Activity::findOrFail($taskId);

        $this->taskId = $taskId;
        $this->reason = $reason ?? $task->emergency_reason ?? '';
        $this->pendingStatus = $status;
        $this->conflict = null;
        $this->step = 'reason';

        $active = app(EmergencySlotService::class)->active([$taskId]);

        if ($active !== null) {
            $this->conflict = (new SingleActiveEmergencyException($active))->activeEmergencyContext();
            $this->step = 'conflict';
        }

        $this->resetValidation();

        // A pending move with the slot already free has nothing left to
        // ask: the conflict that bounced it is gone, so just let it happen.
        if ($this->pendingStatus !== null && $this->conflict === null) {
            $this->apply();

            return;
        }

        $this->showModal = true;
    }

    /**
     * Keep the Emergência that already holds the slot: the operation the
     * user started — classifying, or moving a concluded Emergência back
     * onto the board — simply doesn't happen.
     */
    public function keepCurrent(): void
    {
        $title = $this->conflict['title'] ?? '';

        $this->close();

        Flux::toast(variant: 'warning', heading: 'Emergência mantida', text: $title);
    }

    /**
     * Choose to replace the current Emergência. A pending *move* has
     * everything it needs already (the item is classified, motivo and
     * all), so it is applied right away; a pending *classification* still
     * needs its motivo first.
     */
    public function replaceCurrent(): void
    {
        if ($this->pendingStatus !== null) {
            $this->apply();

            return;
        }

        $this->step = 'reason';
    }

    public function confirm(): void
    {
        $this->validate([
            'reason' => 'required|string|max:1000',
        ], [
            'reason.required' => 'Informe por que isso é uma Emergência.',
        ]);

        $this->apply();
    }

    /**
     * Perform the pending operation, handing the slot over first when the
     * user chose to substitute. Both writes share one transaction inside
     * {@see EmergencySlotService::swap()}, so a refused promotion never
     * leaves the board with zero Emergências.
     */
    private function apply(): void
    {
        $task = Activity::findOrFail($this->taskId);
        $conflictId = $this->conflict['id'] ?? null;
        $pendingStatus = $this->pendingStatus;
        $reason = $this->reason;

        try {
            app(EmergencySlotService::class)->swap($conflictId, $task->id, function () use ($task, $pendingStatus, $reason): void {
                if ($pendingStatus !== null) {
                    $task->update(['status' => ActivityStatus::from($pendingStatus)]);

                    return;
                }

                $task->update([
                    'service_class' => ServiceClass::Emergency,
                    'emergency_reason' => $reason,
                ]);
            });
        } catch (SingleActiveEmergencyException $e) {
            // The slot changed hands between opening this modal and
            // confirming: ask the same question again with fresh data
            // instead of overwriting a newer decision.
            $this->conflict = $e->activeEmergencyContext();
            $this->step = 'conflict';

            return;
        }

        $heading = $pendingStatus !== null ? 'Emergência movida' : 'Emergência classificada';

        $this->close();

        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: $heading, text: $task->title);
    }

    public function cancel(): void
    {
        $this->close();
    }

    private function close(): void
    {
        $this->showModal = false;
        $this->reset('taskId', 'reason', 'step', 'conflict', 'pendingStatus');
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
