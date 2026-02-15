<?php

use Livewire\Component;

new class extends Component
{
    public ?string $slug = null;

    public function mount(?string $slug = null): void
    {
        $this->slug = $slug;
    }
}

?>

<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <flux:heading size="xl">{{ $slug ? 'Editar Documento' : 'Novo Documento' }}</flux:heading>
    <flux:text class="text-zinc-400">Em construção...</flux:text>
</div>
