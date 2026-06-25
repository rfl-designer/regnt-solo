<?php

use App\Enums\ActivityType;
use App\Models\Activity;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public bool $showEditor = false;

    public ?int $editingDraftId = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('nullable|string')]
    public string $note = '';

    public bool $showDeleteModal = false;

    public ?int $deletingDraftId = null;

    public ?string $deletingDraftTitle = null;

    /**
     * Drafts (type=Draft) — ideas outside any status board, honoring the search.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    #[Computed]
    public function drafts(): \Illuminate\Database\Eloquent\Collection
    {
        $query = Activity::query()->drafts();

        if ($this->search !== '') {
            $query->whereRaw('lower(title) like ?', ['%'.mb_strtolower($this->search).'%']);
        }

        return $query->latest()->get();
    }

    /**
     * Open the editor to create a new draft.
     */
    public function newDraft(): void
    {
        $this->reset('editingDraftId', 'title', 'note');
        $this->resetValidation();
        $this->showEditor = true;
    }

    /**
     * Open the editor to edit an existing draft.
     */
    public function editDraft(int $draftId): void
    {
        $draft = Activity::query()->drafts()->findOrFail($draftId);

        $this->editingDraftId = $draft->id;
        $this->title = $draft->title;
        $this->note = $draft->description ?? '';
        $this->resetValidation();
        $this->showEditor = true;
    }

    /**
     * Create or update the draft from the editor.
     */
    public function saveDraft(): void
    {
        $this->validate();

        $title = $this->title;

        if ($this->editingDraftId !== null) {
            $draft = Activity::query()->drafts()->findOrFail($this->editingDraftId);
            $draft->update([
                'title' => $this->title,
                'description' => $this->note !== '' ? $this->note : null,
            ]);
            $heading = 'Ideia atualizada';
        } else {
            Activity::create([
                'type' => ActivityType::Draft,
                'title' => $this->title,
                'description' => $this->note !== '' ? $this->note : null,
            ]);
            $heading = 'Ideia criada';
        }

        $this->showEditor = false;
        $this->reset('editingDraftId', 'title', 'note');
        unset($this->drafts);

        Flux::toast(variant: 'success', heading: $heading, text: $title);
    }

    public function confirmDelete(int $draftId): void
    {
        $draft = Activity::query()->drafts()->findOrFail($draftId);

        $this->deletingDraftId = $draft->id;
        $this->deletingDraftTitle = $draft->title;
        $this->showDeleteModal = true;
    }

    public function deleteDraft(): void
    {
        if ($this->deletingDraftId === null) {
            return;
        }

        $draft = Activity::query()->drafts()->findOrFail($this->deletingDraftId);
        $title = $draft->title;

        $draft->delete();

        $this->reset('deletingDraftId', 'deletingDraftTitle', 'showDeleteModal');
        unset($this->drafts);

        Flux::toast(variant: 'success', heading: 'Ideia excluída', text: $title);
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:icon name="light-bulb" class="size-7 text-amber-400" />
            <flux:heading size="xl">Ideias</flux:heading>
        </div>

        <flux:button variant="primary" icon="plus" size="sm" wire:click="newDraft">Nova ideia</flux:button>
    </div>

    <flux:text class="-mt-4 text-sm text-zinc-400">
        Ideias ainda imaturas — só título e nota, fora de qualquer board. Promova rodando <code>/to-prd</code> ou <code>/to-issues</code>.
    </flux:text>

    {{-- Search --}}
    <flux:input
        icon="magnifying-glass"
        wire:model.live.debounce.300ms="search"
        placeholder="Buscar ideias..."
        size="sm"
        class="max-w-xs"
    />

    @if ($this->drafts->isEmpty())
        <div class="flex flex-1 items-center justify-center">
            <div class="text-center">
                <flux:icon name="light-bulb" class="mx-auto mb-3 size-12 text-zinc-500" />
                <flux:heading size="lg">Nenhuma ideia</flux:heading>
                <flux:text class="mt-1">Capture uma ideia para começar.</flux:text>
                <flux:button variant="primary" icon="plus" size="sm" class="mt-3" wire:click="newDraft">Nova ideia</flux:button>
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->drafts as $draft)
                <div
                    wire:key="draft-{{ $draft->id }}"
                    class="group flex flex-col gap-2 rounded-lg border border-zinc-700 bg-zinc-800 p-4 transition hover:border-amber-500/40"
                >
                    <div class="flex items-start justify-between gap-2">
                        <button
                            type="button"
                            wire:click="editDraft({{ $draft->id }})"
                            class="min-w-0 flex-1 text-left"
                        >
                            <span class="text-sm font-medium text-zinc-200">{{ $draft->title }}</span>
                        </button>

                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            class="shrink-0 opacity-0 transition group-hover:opacity-100"
                            wire:click="confirmDelete({{ $draft->id }})"
                        />
                    </div>

                    @if ($draft->description)
                        <p class="line-clamp-3 text-sm text-zinc-400">{{ Str::limit(strip_tags(Str::markdown($draft->description)), 160) }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- Create / edit editor modal --}}
    <flux:modal wire:model.self="showEditor" class="w-xl">
        <form wire:submit="saveDraft" class="space-y-4">
            <flux:heading size="lg">{{ $editingDraftId ? 'Editar ideia' : 'Nova ideia' }}</flux:heading>

            <flux:input wire:model="title" label="Título" placeholder="A ideia em uma linha" />

            <flux:field>
                <flux:label>Nota</flux:label>
                <flux:editor wire:model="note" placeholder="Elabore a ideia (opcional)..." />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">Salvar</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete confirmation modal --}}
    <flux:modal wire:model.self="showDeleteModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir ideia</flux:heading>
                <flux:text class="mt-1">
                    Tem certeza que deseja excluir <strong>{{ $deletingDraftTitle }}</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteDraft" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deleteDraft">Excluir</span>
                    <span wire:loading wire:target="deleteDraft">Excluindo...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
