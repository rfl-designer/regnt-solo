@props(['type' => 'all']) {{-- 'status', 'priority', 'all' --}}

<flux:dropdown position="bottom" align="end">
    <flux:button variant="ghost" size="sm" icon="swatch" class="text-zinc-400">
        Legenda
    </flux:button>

    <flux:menu class="w-64">
        @if ($type === 'status' || $type === 'all')
            <flux:menu.group heading="Status">
                @foreach (\App\Enums\TaskStatus::cases() as $status)
                    <div class="flex items-center gap-2 px-3 py-1.5">
                        <flux:badge size="sm" color="{{ $status->color() }}" icon="{{ $status->icon() }}" class="w-24 justify-center">
                            {{ $status->label() }}
                        </flux:badge>
                        <flux:text size="xs" class="text-zinc-500">
                            @switch($status)
                                @case(\App\Enums\TaskStatus::Inbox)
                                    Não triada
                                    @break
                                @case(\App\Enums\TaskStatus::Backlog)
                                    Para depois
                                    @break
                                @case(\App\Enums\TaskStatus::Todo)
                                    Pronta para fazer
                                    @break
                                @case(\App\Enums\TaskStatus::Doing)
                                    Em progresso
                                    @break
                                @case(\App\Enums\TaskStatus::Done)
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
                @foreach (\App\Enums\TaskPriority::cases() as $priority)
                    <div class="flex items-center gap-2 px-3 py-1.5">
                        <flux:badge size="sm" color="{{ $priority->color() }}" icon="{{ $priority->icon() }}" class="w-24 justify-center">
                            {{ $priority->label() }}
                        </flux:badge>
                        <flux:text size="xs" class="text-zinc-500">
                            @switch($priority)
                                @case(\App\Enums\TaskPriority::Urgent)
                                    Resolver imediatamente
                                    @break
                                @case(\App\Enums\TaskPriority::High)
                                    Alta prioridade
                                    @break
                                @case(\App\Enums\TaskPriority::Medium)
                                    Prioridade padrão
                                    @break
                                @case(\App\Enums\TaskPriority::Low)
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
