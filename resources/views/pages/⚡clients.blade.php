<?php

use App\Enums\ClientChannel;
use App\Models\Client;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $tab = 'active';

    public bool $showForm = false;

    public ?int $editingClientId = null;

    public string $name = '';

    public string $color = '#3b82f6';

    public ?int $updateDay = null;

    public ?string $updateTime = null;

    public string $channel = 'other';

    public string $responseAgreement = '';

    public string $notes = '';

    public bool $showDeleteModal = false;

    public ?int $deletingClientId = null;

    public ?string $deletingClientName = null;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Client>
     */
    #[Computed]
    public function clients(): \Illuminate\Database\Eloquent\Collection
    {
        return Client::query()
            ->when($this->tab === 'active', fn ($query) => $query->active())
            ->when($this->tab === 'archived', fn ($query) => $query->archived())
            ->withCount(['projects', 'activities', 'stakeholders'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function getDaysOfWeek(): array
    {
        return [
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
            7 => 'Domingo',
        ];
    }

    public function openForm(?int $clientId = null): void
    {
        if ($clientId) {
            $client = Client::findOrFail($clientId);

            $this->editingClientId = $client->id;
            $this->name = $client->name;
            $this->color = $client->color;
            $this->updateDay = $client->update_day;
            $this->updateTime = $client->update_time?->format('H:i');
            $this->channel = $client->channel->value;
            $this->responseAgreement = $client->response_agreement ?? '';
            $this->notes = $client->notes ?? '';
        } else {
            $this->resetForm();
        }

        $this->showForm = true;
    }

    public function saveClient(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|max:7',
            'updateDay' => 'nullable|integer|min:1|max:7',
            'updateTime' => 'nullable|date_format:H:i',
            'channel' => 'required|in:'.implode(',', array_column(ClientChannel::cases(), 'value')),
            'responseAgreement' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $slug = Str::slug($this->name);

        $slugExists = Client::where('slug', $slug)
            ->when($this->editingClientId, fn ($query) => $query->where('id', '!=', $this->editingClientId))
            ->exists();

        if ($slugExists) {
            $this->addError('name', 'Já existe um cliente com este nome.');

            return;
        }

        $data = [
            'name' => $this->name,
            'slug' => $slug,
            'color' => $this->color,
            'update_day' => $this->updateDay,
            'update_time' => $this->updateTime ?: null,
            'channel' => $this->channel,
            'response_agreement' => $this->responseAgreement ?: null,
            'notes' => $this->notes ?: null,
        ];

        if ($this->editingClientId) {
            $client = Client::findOrFail($this->editingClientId);
            $client->update($data);
            $message = 'Cliente atualizado';
        } else {
            Client::create($data);
            $message = 'Cliente criado';
        }

        $this->resetForm();
        $this->showForm = false;
        unset($this->clients);

        Flux::toast(text: $message, variant: 'success', heading: 'Sucesso');
    }

    public function toggleActive(int $clientId): void
    {
        $client = Client::findOrFail($clientId);
        $client->update(['is_active' => ! $client->is_active]);

        unset($this->clients);

        $status = $client->is_active ? 'reativado' : 'arquivado';
        Flux::toast(text: "Cliente foi {$status}", variant: 'success', heading: 'Status alterado');
    }

    public function confirmDelete(int $clientId): void
    {
        $client = Client::findOrFail($clientId);
        $this->deletingClientId = $client->id;
        $this->deletingClientName = $client->name;
        $this->showDeleteModal = true;
    }

    public function deleteClient(): void
    {
        if ($this->deletingClientId === null) {
            return;
        }

        $client = Client::findOrFail($this->deletingClientId);
        $name = $client->name;
        $client->delete();

        $this->reset('deletingClientId', 'deletingClientName', 'showDeleteModal');
        unset($this->clients);

        Flux::toast(text: 'Cliente removido', variant: 'success', heading: "{$name} excluído");
    }

    private function resetForm(): void
    {
        $this->reset('editingClientId', 'name', 'color', 'updateDay', 'updateTime', 'channel', 'responseAgreement', 'notes');
        $this->color = '#3b82f6';
        $this->channel = 'other';
    }
};

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Clientes</flux:heading>
        <flux:button wire:click="openForm" icon="plus" size="sm">
            Novo Cliente
        </flux:button>
    </div>

    <flux:tab.group>
        <flux:tabs wire:model="tab">
            <flux:tab name="active" icon="building-office-2">Ativos</flux:tab>
            <flux:tab name="archived" icon="archive-box">Arquivados</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="active">
            @include('pages.partials.clients-list')
        </flux:tab.panel>

        <flux:tab.panel name="archived">
            @include('pages.partials.clients-list')
        </flux:tab.panel>
    </flux:tab.group>

    {{-- Client Form Modal --}}
    <flux:modal wire:model.self="showForm" class="md:w-[32rem]">
        <form wire:submit="saveClient" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ $editingClientId ? 'Editar Cliente' : 'Novo Cliente' }}</flux:heading>
            </div>

            <flux:field>
                <flux:label>Nome</flux:label>
                <flux:input wire:model="name" placeholder="Ex: Acme Corp" />
                <flux:error name="name" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Cor</flux:label>
                    <input
                        type="color"
                        wire:model="color"
                        class="h-10 w-full cursor-pointer rounded-lg border border-zinc-300 bg-white p-1 dark:border-zinc-600 dark:bg-zinc-700"
                    />
                    <flux:error name="color" />
                </flux:field>

                <flux:field>
                    <flux:label>Canal</flux:label>
                    <flux:select wire:model="channel">
                        @foreach (ClientChannel::cases() as $c)
                            <option value="{{ $c->value }}">{{ $c->label() }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Dia da semana (update)</flux:label>
                    <flux:select wire:model="updateDay" placeholder="Nenhum">
                        <option value="">Nenhum</option>
                        @foreach ($this->getDaysOfWeek() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>Hora-alvo</flux:label>
                    <flux:input type="time" wire:model="updateTime" />
                    <flux:error name="updateTime" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Acordo de resposta</flux:label>
                <flux:textarea wire:model="responseAgreement" rows="2" placeholder="Ex: responder em até 24h úteis" />
                <flux:error name="responseAgreement" />
            </flux:field>

            <flux:field>
                <flux:label>Notas</flux:label>
                <flux:textarea wire:model="notes" rows="3" />
                <flux:error name="notes" />
            </flux:field>

            <div class="flex justify-end gap-2 border-t border-zinc-700 pt-4">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveClient">{{ $editingClientId ? 'Salvar' : 'Criar' }}</span>
                    <span wire:loading wire:target="saveClient">Salvando...</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Modal --}}
    <flux:modal wire:model.self="showDeleteModal" class="md:w-96">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Excluir cliente</flux:heading>
                <flux:text class="mt-1">
                    Tem certeza que deseja excluir <strong>{{ $deletingClientName }}</strong>? Esta ação não pode ser desfeita.
                </flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteClient" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="deleteClient">Excluir</span>
                    <span wire:loading wire:target="deleteClient">Excluindo...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
