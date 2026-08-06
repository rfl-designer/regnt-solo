<?php

use App\Mail\StakeholderAccessLink;
use App\Models\Client;
use App\Models\Project;
use App\Models\Stakeholder;
use Flux\Flux;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public ?int $projectId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    #[Validate('nullable|exists:clients,id')]
    public ?int $clientId = null;

    /** @var array<int, int|null> */
    public array $stakeholderClientIds = [];

    #[On('open-stakeholders-modal')]
    public function open(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->reset('name', 'email', 'clientId');
        $this->syncStakeholderClientIds();

        Flux::modal('project-stakeholders')->show();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Client>
     */
    #[Computed]
    public function clients(): \Illuminate\Database\Eloquent\Collection
    {
        return Client::active()->orderBy('name')->get();
    }

    #[Computed]
    public function project(): ?Project
    {
        if (! $this->projectId) {
            return null;
        }

        return Project::with('stakeholders')->find($this->projectId);
    }

    public function addStakeholder(): void
    {
        $this->validate();

        $stakeholder = Stakeholder::create([
            'project_id' => $this->projectId,
            'client_id' => $this->clientId,
            'name' => $this->name,
            'email' => $this->email,
        ]);

        Mail::to($stakeholder->email)->send(new StakeholderAccessLink($stakeholder));

        $this->reset('name', 'email', 'clientId');
        unset($this->project);
        $this->syncStakeholderClientIds();

        Flux::toast(
            variant: 'success',
            heading: 'Stakeholder adicionado',
            text: 'Email de acesso enviado para '.$stakeholder->email,
        );
    }

    /**
     * Update the client linked to an existing stakeholder, without touching
     * its access_token or the portal mechanism.
     */
    public function updateStakeholderClient(int $stakeholderId): void
    {
        $this->validate([
            "stakeholderClientIds.{$stakeholderId}" => 'nullable|exists:clients,id',
        ]);

        $stakeholder = Stakeholder::findOrFail($stakeholderId);
        $stakeholder->update(['client_id' => $this->stakeholderClientIds[$stakeholderId] ?: null]);

        unset($this->project);

        Flux::toast(
            variant: 'success',
            heading: 'Cliente atualizado',
            text: 'Vínculo de cliente atualizado para '.$stakeholder->name,
        );
    }

    /**
     * Refresh the per-stakeholder client select values from the database.
     */
    private function syncStakeholderClientIds(): void
    {
        $this->stakeholderClientIds = Stakeholder::query()
            ->where('project_id', $this->projectId)
            ->pluck('client_id', 'id')
            ->all();
    }

    public function resendEmail(int $stakeholderId): void
    {
        $stakeholder = Stakeholder::findOrFail($stakeholderId);

        Mail::to($stakeholder->email)->send(new StakeholderAccessLink($stakeholder));

        Flux::toast(
            variant: 'success',
            heading: 'Email reenviado',
            text: 'Link de acesso enviado para '.$stakeholder->email,
        );
    }

    public function removeStakeholder(int $stakeholderId): void
    {
        $stakeholder = Stakeholder::findOrFail($stakeholderId);
        $stakeholder->delete();

        unset($this->project);

        Flux::toast(
            variant: 'success',
            heading: 'Stakeholder removido',
            text: $stakeholder->name.' foi removido do projeto.',
        );
    }
}

?>

<div>
    <flux:modal name="project-stakeholders" class="w-full max-w-2xl">
        <div class="space-y-6">
            {{-- Header --}}
            <div>
                <flux:heading size="lg">Stakeholders</flux:heading>
                <flux:text class="text-sm text-zinc-400">
                    Pessoas que podem acompanhar o projeto em modo somente leitura.
                </flux:text>
            </div>

            @if ($this->project)
                {{-- Add New Stakeholder Form --}}
                <form wire:submit="addStakeholder" class="space-y-4 rounded-lg border border-zinc-700 bg-zinc-900/50 p-4">
                    <flux:heading size="sm">Adicionar stakeholder</flux:heading>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:input
                            wire:model="name"
                            label="Nome"
                            placeholder="Ex: João Silva"
                        />

                        <flux:input
                            wire:model="email"
                            type="email"
                            label="Email"
                            placeholder="joao@empresa.com"
                        />
                    </div>

                    <flux:field>
                        <flux:label>Cliente</flux:label>
                        <flux:select wire:model="clientId" placeholder="Sem cliente">
                            <flux:select.option value="">Sem cliente</flux:select.option>
                            @foreach ($this->clients as $client)
                                <flux:select.option :value="$client->id" wire:key="stakeholder-form-client-{{ $client->id }}">
                                    {{ $client->name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="clientId" />
                    </flux:field>

                    <div class="flex justify-end">
                        <flux:button
                            type="submit"
                            variant="primary"
                            size="sm"
                            icon="paper-airplane"
                            wire:loading.attr="disabled"
                            wire:target="addStakeholder"
                        >
                            <span wire:loading.remove wire:target="addStakeholder">Adicionar e enviar link</span>
                            <span wire:loading wire:target="addStakeholder" class="inline-flex items-center gap-2">
                                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                <span>Enviando...</span>
                            </span>
                        </flux:button>
                    </div>
                </form>

                {{-- Stakeholders List --}}
                <div class="space-y-3">
                    <flux:heading size="sm">Stakeholders do projeto</flux:heading>

                    @forelse ($this->project->stakeholders as $stakeholder)
                        <div
                            wire:key="stakeholder-{{ $stakeholder->id }}"
                            class="flex items-center justify-between rounded-lg border border-zinc-700 bg-zinc-900/50 p-3"
                        >
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-zinc-700">
                                    <flux:icon name="user" class="size-5 text-zinc-400" />
                                </div>
                                <div>
                                    <div class="font-medium text-zinc-200">{{ $stakeholder->name }}</div>
                                    <div class="text-sm text-zinc-500">{{ $stakeholder->email }}</div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                {{-- Client Link --}}
                                <flux:select
                                    wire:model.live="stakeholderClientIds.{{ $stakeholder->id }}"
                                    wire:change="updateStakeholderClient({{ $stakeholder->id }})"
                                    size="sm"
                                    placeholder="Sem cliente"
                                    class="w-40"
                                >
                                    <flux:select.option value="">Sem cliente</flux:select.option>
                                    @foreach ($this->clients as $client)
                                        <flux:select.option :value="$client->id" wire:key="stakeholder-{{ $stakeholder->id }}-client-{{ $client->id }}">
                                            {{ $client->name }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>

                                {{-- Access Status --}}
                                @if ($stakeholder->last_accessed_at)
                                    <flux:badge size="sm" color="emerald" icon="check-circle">
                                        Acessou {{ $stakeholder->last_accessed_at->diffForHumans() }}
                                    </flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc" icon="clock">
                                        Nunca acessou
                                    </flux:badge>
                                @endif

                                {{-- Actions --}}
                                <div class="flex items-center gap-1">
                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        wire:click="resendEmail({{ $stakeholder->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="resendEmail({{ $stakeholder->id }})"
                                        title="Reenviar link"
                                    >
                                        <flux:icon
                                            wire:loading.remove
                                            wire:target="resendEmail({{ $stakeholder->id }})"
                                            name="paper-airplane"
                                            class="size-3.5"
                                        />
                                        <flux:icon
                                            wire:loading
                                            wire:target="resendEmail({{ $stakeholder->id }})"
                                            name="arrow-path"
                                            class="size-3.5 animate-spin"
                                        />
                                    </flux:button>

                                    <flux:button
                                        variant="ghost"
                                        size="xs"
                                        icon="trash"
                                        wire:click="removeStakeholder({{ $stakeholder->id }})"
                                        wire:confirm="Tem certeza que deseja remover {{ $stakeholder->name }}? O link de acesso será invalidado."
                                        wire:loading.attr="disabled"
                                        wire:target="removeStakeholder({{ $stakeholder->id }})"
                                        title="Remover stakeholder"
                                        class="text-red-400 hover:text-red-300"
                                    />
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-700 py-8">
                            <flux:icon name="user-group" class="mb-3 size-10 text-zinc-600" />
                            <flux:text class="text-sm text-zinc-500">Nenhum stakeholder adicionado.</flux:text>
                            <flux:text class="mt-1 text-xs text-zinc-600">Adicione pessoas para acompanhar o projeto.</flux:text>
                        </div>
                    @endforelse
                </div>
            @endif

            {{-- Footer --}}
            <div class="flex justify-end border-t border-zinc-700 pt-4">
                <flux:button variant="ghost" x-on:click="$flux.modal('project-stakeholders').close()">
                    Fechar
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
