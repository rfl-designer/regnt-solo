<div class="space-y-4 pt-4">
    @if ($this->clients->isEmpty())
        <div class="flex flex-1 items-center justify-center py-16">
            <div class="text-center">
                <flux:icon name="building-office-2" class="mx-auto mb-3 size-12 text-zinc-500" />
                <flux:heading size="lg">Nenhum cliente</flux:heading>
                <flux:text class="mt-1">
                    {{ $tab === 'archived' ? 'Nenhum cliente arquivado.' : 'Cadastre clientes para organizar projetos e tasks.' }}
                </flux:text>
                @if ($tab !== 'archived')
                    <flux:button wire:click="openForm" icon="plus" size="sm" class="mt-4">
                        Novo cliente
                    </flux:button>
                @endif
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Cliente</flux:table.column>
                <flux:table.column>Canal</flux:table.column>
                <flux:table.column>Cadência</flux:table.column>
                <flux:table.column>Vínculos</flux:table.column>
                <flux:table.column align="end">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->clients as $client)
                    <flux:table.row :key="$client->id">
                        <flux:table.cell variant="strong">
                            <div class="flex items-center gap-2">
                                <div class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $client->color }}"></div>
                                {{ $client->name }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" color="{{ $client->channel->color() }}" icon="{{ $client->channel->icon() }}">
                                {{ $client->channel->label() }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($client->update_day)
                                <span>
                                    {{ $client->updateDayLabel() }}
                                    @if ($client->update_time)
                                        às {{ $client->update_time->format('H:i') }}
                                    @endif
                                </span>
                            @else
                                <span class="text-zinc-500">—</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center gap-2 text-xs text-zinc-400">
                                <span>{{ $client->projects_count }} {{ \Illuminate\Support\Str::plural('projeto', $client->projects_count) }}</span>
                                <span>&middot;</span>
                                <span>{{ $client->activities_count }} {{ \Illuminate\Support\Str::plural('task', $client->activities_count) }}</span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center justify-end gap-1">
                                {{-- Atalho para a página Updates com o cliente
                                     já escolhido (issue #149). O update mora
                                     lá, junto da fila e do histórico; aqui é
                                     só a porta. --}}
                                @if ($client->is_active)
                                    <flux:button
                                        :href="route('updates', ['client' => $client->slug])"
                                        wire:navigate
                                        size="sm"
                                        variant="ghost"
                                        icon="paper-airplane"
                                        title="Gerar update"
                                        data-test="client-generate-update-{{ $client->slug }}"
                                    />
                                @endif

                                <flux:button
                                    wire:click="toggleActive({{ $client->id }})"
                                    size="sm"
                                    variant="ghost"
                                    :icon="$client->is_active ? 'archive-box' : 'arrow-path'"
                                    :title="$client->is_active ? 'Arquivar' : 'Reativar'"
                                />

                                <flux:button
                                    wire:click="openForm({{ $client->id }})"
                                    size="sm"
                                    variant="ghost"
                                    icon="pencil"
                                />

                                <flux:button
                                    wire:click="confirmDelete({{ $client->id }})"
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    class="text-red-400 hover:text-red-300"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
