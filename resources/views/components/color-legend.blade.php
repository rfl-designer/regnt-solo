@props(['type' => 'all']) {{-- 'status', 'service_class', 'all' --}}

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

        @if ($type === 'service_class' || $type === 'all')
            @if ($type === 'all')
                <flux:menu.separator />
            @endif

            <flux:menu.group heading="Classe de serviço">
                @foreach (\App\Enums\ServiceClass::cases() as $serviceClass)
                    <div class="flex items-center gap-3 px-3 py-1.5">
                        <flux:badge size="sm" color="{{ $serviceClass->color() }}" icon="{{ $serviceClass->icon() }}" class="w-32 shrink-0 justify-center">
                            {{ $serviceClass->label() }}
                        </flux:badge>
                        <flux:text size="xs" class="whitespace-nowrap text-zinc-500">
                            @switch($serviceClass)
                                @case(\App\Enums\ServiceClass::Emergency)
                                    Crítico, fura o WIP
                                    @break
                                @case(\App\Enums\ServiceClass::FixedDate)
                                    Tem prazo definido
                                    @break
                                @case(\App\Enums\ServiceClass::Standard)
                                    Fila FIFO padrão
                                    @break
                                @case(\App\Enums\ServiceClass::Intangible)
                                    Sem urgência definida
                                    @break
                            @endswitch
                        </flux:text>
                    </div>
                @endforeach
            </flux:menu.group>
        @endif
    </flux:menu>
</flux:dropdown>
