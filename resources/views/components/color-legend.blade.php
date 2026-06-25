@props(['type' => 'all']) {{-- 'status', 'priority', 'all' --}}

<flux:dropdown position="bottom" align="end">
    <flux:button variant="ghost" size="sm" icon="swatch" class="text-zinc-400">
        Legenda
    </flux:button>

    <flux:menu class="min-w-72">
        @if ($type === 'status' || $type === 'all')
            <flux:menu.group heading="Status">
                @foreach (\App\Enums\ActivityStatus::cases() as $status)
                    <div class="flex items-center gap-3 px-3 py-1.5">
                        <flux:badge size="sm" color="{{ $status->color() }}" icon="{{ $status->icon() }}" class="w-32 shrink-0 justify-center">
                            {{ $status->label() }}
                        </flux:badge>
                        <flux:text size="xs" class="whitespace-nowrap text-zinc-500">
                            @switch($status)
                                @case(\App\Enums\ActivityStatus::Inbox)
                                    Não triada
                                    @break
                                @case(\App\Enums\ActivityStatus::Backlog)
                                    Para depois
                                    @break
                                @case(\App\Enums\ActivityStatus::Todo)
                                    Pronta para fazer
                                    @break
                                @case(\App\Enums\ActivityStatus::Doing)
                                    Em progresso
                                    @break
                                @case(\App\Enums\ActivityStatus::Done)
                                    Concluída
                                    @break
                            @endswitch
                        </flux:text>
                    </div>
                @endforeach
            </flux:menu.group>
        @endif

        @if ($type === 'priority' || $type === 'all')
            @if ($type === 'all')
                <flux:menu.separator />
            @endif

            <flux:menu.group heading="Prioridade">
                @foreach (\App\Enums\ActivityPriority::cases() as $priority)
                    <div class="flex items-center gap-3 px-3 py-1.5">
                        <flux:badge size="sm" color="{{ $priority->color() }}" icon="{{ $priority->icon() }}" class="w-32 shrink-0 justify-center">
                            {{ $priority->label() }}
                        </flux:badge>
                        <flux:text size="xs" class="whitespace-nowrap text-zinc-500">
                            @switch($priority)
                                @case(\App\Enums\ActivityPriority::Urgent)
                                    Crítico
                                    @break
                                @case(\App\Enums\ActivityPriority::High)
                                    Alta prioridade
                                    @break
                                @case(\App\Enums\ActivityPriority::Medium)
                                    Prioridade padrão
                                    @break
                                @case(\App\Enums\ActivityPriority::Low)
                                    Pode esperar
                                    @break
                            @endswitch
                        </flux:text>
                    </div>
                @endforeach
            </flux:menu.group>
        @endif
    </flux:menu>
</flux:dropdown>
