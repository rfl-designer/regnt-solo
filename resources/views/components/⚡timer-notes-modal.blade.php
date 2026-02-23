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

    public string $itemName = '';

    public string $itemType = 'task';

    public bool $isFocusSession = false;

    public ?int $focusRating = null;

    /**
     * Open the modal and load the time entry data.
     */
    #[On('open-timer-notes')]
    public function openNotes(int $entryId): void
    {
        $entry = TimeEntry::with(['task', 'feature'])->findOrFail($entryId);

        $this->entryId = $entry->id;

        if ($entry->feature_id !== null && $entry->feature !== null) {
            $this->itemName = $entry->feature->title;
            $this->itemType = 'feature';
        } else {
            $this->itemName = $entry->task->title ?? '';
            $this->itemType = 'task';
        }

        $this->notes = '';
        $this->isFocusSession = (bool) $entry->is_focus_session;
        $this->focusRating = null;
        $this->showModal = true;
    }

    /**
     * Save the time entry with notes and stop the timer.
     */
    public function saveWithNotes(): void
    {
        $this->stopEntry(notes: $this->notes, toastText: 'Notas salvas com sucesso.');
    }

    /**
     * Skip notes and just stop the timer.
     */
    public function skipNotes(): void
    {
        $this->stopEntry(toastText: 'Timer finalizado sem notas.');
    }

    /**
     * Stop the time entry, optionally saving notes and focus rating.
     */
    private function stopEntry(?string $notes = null, string $toastText = ''): void
    {
        $entry = TimeEntry::findOrFail($this->entryId);

        $data = ['stopped_at' => now()];

        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        if ($this->isFocusSession && $this->focusRating !== null) {
            $data['focus_rating'] = $this->focusRating;
        }

        $entry->update($data);

        $this->showModal = false;

        $this->dispatch('timer-updated');

        Flux::toast(variant: 'success', heading: 'Timer parado', text: $toastText);
    }
};

?>

<div>
    <flux:modal wire:model.self="showModal" :dismissible="false" :closable="false" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Notas do Timer</flux:heading>
                <flux:text class="mt-1">
                    @if ($isFocusSession)
                        <span class="mr-1">🎯</span>
                    @endif
                    {{ $itemName }}
                </flux:text>
            </div>

            {{-- Focus Rating --}}
            @if ($isFocusSession)
                <div class="space-y-2">
                    <flux:text class="text-sm font-medium text-zinc-300">Como foi a sessão?</flux:text>
                    <div class="flex items-center gap-1">
                        @for ($i = 1; $i <= 5; $i++)
                            <button
                                type="button"
                                wire:click="$set('focusRating', {{ $i }})"
                                class="text-2xl transition-transform hover:scale-110 {{ $focusRating !== null && $focusRating >= $i ? 'opacity-100' : 'opacity-30' }}"
                            >
                                ⭐
                            </button>
                        @endfor
                    </div>
                </div>
            @endif

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
