<?php

use App\Models\TimeEntry;
use Flux\Flux;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public ?int $entryId = null;

    public string $notes = '';

    public string $taskName = '';

    /**
     * Open the modal and load the time entry data.
     */
    #[On('open-timer-notes')]
    public function openNotes(int $entryId): void
    {
        $entry = TimeEntry::with('task')->findOrFail($entryId);

        $this->entryId = $entry->id;
        $this->taskName = $entry->task->title ?? '';
        $this->notes = '';
        $this->showModal = true;
    }

    /**
     * Save the time entry with notes and stop the timer.
     */
    public function saveWithNotes(): void
    {
        $entry = TimeEntry::findOrFail($this->entryId);

        $entry->update([
            'stopped_at' => now(),
            'notes' => $this->notes,
        ]);

        $this->showModal = false;

        $this->dispatch('timer-updated');

        Flux::toast(variant: 'success', heading: 'Timer parado', text: 'Notas salvas com sucesso.');
    }

    /**
     * Skip notes and just stop the timer.
     */
    public function skipNotes(): void
    {
        $entry = TimeEntry::findOrFail($this->entryId);

        $entry->update([
            'stopped_at' => now(),
        ]);

        $this->showModal = false;

        $this->dispatch('timer-updated');

        Flux::toast(variant: 'success', heading: 'Timer parado', text: 'Timer finalizado sem notas.');
    }
};

?>

<div>
    <flux:modal wire:model.self="showModal" :dismissible="false" :closable="false" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Notas do Timer</flux:heading>
                <flux:text class="mt-1">{{ $taskName }}</flux:text>
            </div>

            <flux:textarea
                wire:model="notes"
                placeholder="O que voce fez?"
                rows="3"
            />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="skipNotes" variant="ghost" wire:loading.attr="disabled">
                    Pular
                </flux:button>
                <flux:button wire:click="saveWithNotes" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveWithNotes">Salvar notas</span>
                    <span wire:loading wire:target="saveWithNotes">Salvando...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
