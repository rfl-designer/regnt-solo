<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                {{-- Visão Geral --}}
                <flux:sidebar.group class="grid">
                    <flux:sidebar.item icon="chart-bar-square" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        Dashboard
                    </flux:sidebar.item>
                </flux:sidebar.group>

                {{-- Planejamento --}}
                <flux:sidebar.group heading="Planejamento" class="grid">
                    <flux:sidebar.item icon="calendar-days" :href="route('daily')" :current="request()->routeIs('daily')" wire:navigate>
                        Hoje
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="calendar" :href="route('weekly')" :current="request()->routeIs('weekly')" wire:navigate>
                        Semana
                        <livewire:weekly-badge />
                    </flux:sidebar.item>
                </flux:sidebar.group>

                {{-- Acompanhamento --}}
                <flux:sidebar.group heading="Acompanhamento" class="grid">
                    <flux:sidebar.item icon="folder" :href="route('projects')" :current="request()->routeIs('projects') || request()->routeIs('project.*') || request()->routeIs('document.*')" wire:navigate>
                        Projetos
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="clock" :href="route('time')" :current="request()->routeIs('time')" wire:navigate>
                        Tempo
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="clipboard-document-check" :href="route('review')" :current="request()->routeIs('review')" wire:navigate>
                        Revisão
                    </flux:sidebar.item>
                </flux:sidebar.group>

                {{-- Análise --}}
                <flux:sidebar.group heading="Análise" class="grid">
                    <flux:sidebar.item icon="chart-bar" :href="route('analytics')" :current="request()->routeIs('analytics')" wire:navigate>
                        Métricas
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="clipboard-document-list" :href="route('templates')" :current="request()->routeIs('templates')" wire:navigate>
                        Templates
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="command-line" :href="route('prompts')" :current="request()->routeIs('prompts')" wire:navigate>
                        Prompts
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Desktop Header -->
        <flux:header class="hidden! lg:block! border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:navbar class="w-full">
                <flux:modal.trigger name="command-palette" shortcut="cmd.k">
                    <flux:input
                        as="button"
                        icon="magnifying-glass"
                        placeholder="Buscar comandos..."
                        kbd="⌘K"
                        class="max-w-xs"
                    />
                </flux:modal.trigger>

                <flux:spacer />

                <livewire:global-timer />
            </flux:navbar>
        </flux:header>

        <!-- Mobile Header -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            {{-- Command Palette trigger for mobile --}}
            <flux:modal.trigger name="command-palette">
                <flux:button variant="ghost" size="sm" icon="magnifying-glass" class="shrink-0" />
            </flux:modal.trigger>

            <livewire:global-timer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <livewire:task-quick-add />
        <livewire:task-modal />
        <livewire:feature-modal />
        <livewire:project-form />
        <livewire:timer-notes-modal />

        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>
