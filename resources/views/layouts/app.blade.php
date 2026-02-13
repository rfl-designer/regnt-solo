<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main
        x-data
        x-on:keydown.window="
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.target.isContentEditable) return;

            const key = $event.key.toLowerCase();

            if ((key === 'k') && ($event.metaKey || $event.ctrlKey)) { $event.preventDefault(); $flux.modal('command-palette').show(); return; }
            if (key === 'n') { $event.preventDefault(); $dispatch('open-quick-add'); $flux.modal('quick-add').show(); }
            if (key === 'k') { $event.preventDefault(); Livewire.navigate('/kanban'); }
            if (key === 'd') { $event.preventDefault(); Livewire.navigate('/daily'); }
            if (key === 'i') { $event.preventDefault(); Livewire.navigate('/inbox'); }
            if (key === 't') { $event.preventDefault(); $dispatch('toggle-timer'); }
        "
    >
        {{ $slot }}
    </flux:main>

    <livewire:command-palette />
</x-layouts::app.sidebar>
