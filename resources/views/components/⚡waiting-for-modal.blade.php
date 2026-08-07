<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $taskId = null;

    public string $status = '';

    public string $waitingFor = '';

    /**
     * The internal wait (Esperando) has no client to auto-fill "esperando
     * quem" from, so the Kanban drag-and-drop and the Task Modal both defer
     * here to collect the name before the move is persisted — this modal is
     * the single blocking checkpoint for that origin-agnostic requirement.
     */
    #[On('open-waiting-for-modal')]
    public function open(int $taskId, string $status): void
    {
        $this->taskId = $taskId;
        $this->status = $status;
        $this->waitingFor = '';
        $this->showModal = true;
    }

    public function confirm(): void
    {
        $this->validate([
            'waitingFor' => 'required|string|max:255',
        ], [
            'waitingFor.required' => 'Informe quem está sendo aguardado.',
        ]);

        $task = Activity::findOrFail($this->taskId);
        $maxOrder = Activity::query()
            ->leaf()
            ->where('status', $this->status)
            ->max('sort_order') ?? -1;

        $task->update([
            'status' => ActivityStatus::from($this->status),
            'waiting_for' => $this->waitingFor,
            'sort_order' => $maxOrder + 1,
        ]);

        $this->showModal = false;
        $this->reset('taskId', 'status', 'waitingFor');

        $this->dispatch('task-updated');

        Flux::toast(variant: 'success', heading: 'Task movida', text: $task->title);
    }

    public function cancel(): void
    {
        $this->showModal = false;
        $this->reset('taskId', 'status', 'waitingFor');
    }
};

?>

<div>
    <flux:modal wire:model.self="showModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Quem está sendo aguardado?</flux:heading>
                <flux:text class="mt-2">
                    Mover para "Esperando" exige informar quem está sendo aguardado.
                </flux:text>
            </div>

            <flux:input
                wire:model="waitingFor"
                label="Esperando quem"
                placeholder="Ex: Designer, DevOps, Suporte..."
                autofocus
            />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="cancel" variant="ghost">
                    Cancelar
                </flux:button>
                <flux:button wire:click="confirm" variant="primary">
                    Confirmar
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
