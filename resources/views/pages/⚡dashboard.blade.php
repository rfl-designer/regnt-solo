<?php

use Livewire\Component;

new class extends Component
{
    //
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <flux:heading size="xl">Dashboard</flux:heading>

    <div class="grid auto-rows-min gap-4 md:grid-cols-3">
        <div class="relative aspect-video overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
            <div class="flex h-full items-center justify-center text-zinc-500">
                <flux:icon name="chart-bar-square" class="size-8" />
            </div>
        </div>
        <div class="relative aspect-video overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
            <div class="flex h-full items-center justify-center text-zinc-500">
                <flux:icon name="inbox" class="size-8" />
            </div>
        </div>
        <div class="relative aspect-video overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
            <div class="flex h-full items-center justify-center text-zinc-500">
                <flux:icon name="clock" class="size-8" />
            </div>
        </div>
    </div>

    <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
        <div class="flex h-full min-h-48 items-center justify-center text-zinc-500">
            <div class="text-center">
                <flux:icon name="squares-2x2" class="mx-auto size-12 mb-3" />
                <flux:heading size="lg">Bem-vindo ao SoloBoard</flux:heading>
                <flux:text class="mt-1">Seu painel de gestão pessoal de projetos.</flux:text>
            </div>
        </div>
    </div>
</div>
