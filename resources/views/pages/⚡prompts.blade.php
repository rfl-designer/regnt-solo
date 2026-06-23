<?php

use App\Models\Prompt;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $content = '';

    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public ?string $deletingName = null;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Prompt>
     */
    #[Computed]
    public function prompts(): \Illuminate\Database\Eloquent\Collection
    {
        return Prompt::query()
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', '%'.$this->search.'%'))
            ->orderByDesc('updated_at')
            ->get();
    }

    public function openForm(?int $id = null): void
    {
        if ($id) {
            $prompt = Prompt::findOrFail($id);
            $this->editingId = $prompt->id;
            $this->name = $prompt->name;
            $this->content = $prompt->content;
        } else {
            $this->resetForm();
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $data = [
            'name' => $this->name,
            'content' => $this->content,
        ];

        if ($this->editingId) {
            Prompt::findOrFail($this->editingId)->update($data);
            $message = 'Prompt atualizado';
        } else {
            Prompt::create($data);
            $message = 'Prompt criado';
        }

        $this->resetForm();
        $this->showForm = false;
        unset($this->prompts);

        Flux::toast(text: $message, variant: 'success', heading: 'Sucesso');
    }

    public function confirmDelete(int $id): void
    {
        $prompt = Prompt::findOrFail($id);
        $this->deletingId = $prompt->id;
        $this->deletingName = $prompt->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if ($this->deletingId === null) {
            return;
        }

        Prompt::findOrFail($this->deletingId)->delete();

        $this->reset('deletingId', 'deletingName', 'showDeleteModal');
        unset($this->prompts);

        Flux::toast(text: 'Prompt removido', variant: 'success', heading: 'Prompt excluído');
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->content = '';
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Prompts</flux:heading>
        <flux:button wire:click="openForm" icon="plus" size="sm">
            Novo prompt
        </flux:button>
    </div>

    <flux:input
        wire:model.live="search"
        icon="magnifying-glass"
        placeholder="Buscar prompts..."
        class="max-w-sm"
    />

    @if ($this->prompts->isEmpty())
        <div class="flex flex-1 items-center justify-center py-16">
            <div class="text-center">
                <flux:icon name="command-line" class="mx-auto mb-3 size-12 text-zinc-500" />
                <flux:heading size="lg">Nenhum prompt</flux:heading>
                <flux:text class="mt-1">Guarde prompts em markdown para reusar nas suas sessões de desenvolvimento.</flux:text>
                <flux:button wire:click="openForm" icon="plus" size="sm" class="mt-4">
                    Novo prompt
                </flux:button>
            </div>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->prompts as $prompt)
                <div
                    wire:key="prompt-{{ $prompt->id }}"
                    x-on:click="navigator.clipboard.writeText(@js($prompt->content)); $flux.toast({ heading: 'Copiado', variant: 'success' })"
                    class="group relative cursor-pointer rounded-lg border border-zinc-700 bg-zinc-800 p-4 pr-16 transition-colors hover:border-zinc-500"
                    title="Clique para copiar"
                >
                    <flux:heading size="sm">{{ $prompt->name }}</flux:heading>

                    <flux:text class="mt-2 line-clamp-2 text-sm text-zinc-400">
                        {{ Str::limit($prompt->content, 120) }}
                    </flux:text>

                    <div class="absolute right-2 top-2 flex gap-1">
                        <flux:button
                            x-on:click.stop
                            wire:click="openForm({{ $prompt->id }})"
                            size="xs"
                            variant="ghost"
                            icon="pencil"
                        />
                        <flux:button
                            x-on:click.stop
                            wire:click="confirmDelete({{ $prompt->id }})"
                            size="xs"
                            variant="ghost"
                            icon="trash"
                            class="text-red-400 hover:text-red-300"
                        />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Prompt Form Modal --}}
    <flux:modal wire:model.self="showForm" class="md:w-[36rem]">
        <form wire:submit="save" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Editar prompt' : 'Novo prompt' }}</flux:heading>
            </div>

            <flux:field>
                <flux:label>Nome</flux:label>
                <flux:input wire:model="name" placeholder="Ex: Implementar feature" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>Conteúdo (Markdown)</flux:label>
                <flux:textarea wire:model="content" rows="12" placeholder="Cole aqui o prompt em markdown..." class="font-mono" />
                <flux:error name="content" />
            </flux:field>

            <div class="flex justify-end gap-2 border-t border-zinc-700 pt-4">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">{{ $editingId ? 'Salvar' : 'Criar' }}</span>
                    <span wire:loading wire:target="save">Salvando...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Modal --}}
    <flux:modal wire:model.self="showDeleteModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir prompt</flux:heading>
                <flux:text class="mt-1">
                    Tem certeza que deseja excluir <strong>{{ $deletingName }}</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="delete">Excluir</span>
                    <span wire:loading wire:target="delete">Excluindo...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
