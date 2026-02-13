# EPIC 0: Scaffold & Fundação

> **Sessão Claude Code:** Setup inicial. Roda uma vez.
> **Estimativa:** ~30min · 3 tasks

---

## Task 0.1 — Criar projeto Laravel + Livewire v4 + Flux UI + Breeze

```yaml
Prompt: |
    Crie um novo projeto Laravel 12 com SQLite. Configure:
    - DB_CONNECTION=sqlite no .env
    - Crie o arquivo database/database.sqlite
    - Instale Livewire v4: composer require livewire/livewire
    - Instale Flux UI: composer require livewire/flux
    - Instale Laravel Breeze com Livewire: composer require laravel/breeze --dev
      - php artisan breeze:install livewire
    - DESABILITE a rota de registro (/register) — remover ou redirecionar para /login
    - Adicione variáveis ao .env:
      - SOLO_USER_EMAIL=admin@soloboard.local
      - SOLO_USER_PASSWORD=password
    - Configure Tailwind CSS 4 via Vite
    - No config/livewire.php configure:
      - make_command.type = 'sfc'
      - component_layout = 'layouts::app'
    - Configure resources/css/app.css:
      - @import 'tailwindcss';
      - @import '../../vendor/livewire/flux/dist/flux.css';
      - @custom-variant dark (&:where(.dark, .dark *));
      - @theme { --font-sans: Inter, sans-serif; }
    - No layout, adicione @fluxAppearance no <head> e @fluxScripts antes de </body>
    - Adicione fonte Inter via fonts.bunny.net
    - Dark mode only (classe .dark sempre aplicada no <html>)
    - Ambiente: Laravel Herd (sem Docker, sem artisan serve)

Acceptance Criteria:
    - App roda via Herd sem erros
    - Livewire v4 SFC funciona
    - Flux UI carrega (<flux:button> renderiza)
    - SQLite é o banco padrão
    - Login funciona, registro está desabilitado
    - Dark mode always-on

Arquivos:
    - composer.json, package.json, .env, .env.example
    - config/livewire.php, config/database.php
    - resources/css/app.css, vite.config.js

Commit: "chore: scaffold Laravel 12 + Livewire v4 SFC + Flux UI + Breeze auth"
```

---

## Task 0.2 — CLAUDE.md e Convenções

```yaml
Prompt: |
    Crie o arquivo CLAUDE.md na raiz com as convenções do projeto.
    (Ver seção 10 da spec v3 para conteúdo completo)

Acceptance Criteria:
    - CLAUDE.md existe na raiz e está legível
    - Destaca: SFC + Flux UI first + wire:sort + dark-only + PT-BR interface

Arquivos: CLAUDE.md
Commit: "chore: add CLAUDE.md with SFC and Flux UI conventions"
```

---

## Task 0.3 — Layout Base com Flux Sidebar Colapsável

```yaml
Prompt: |
    Crie o layout base em resources/views/layouts/app.blade.php usando Flux UI:
    - <flux:sidebar> colapsável (comportamento nativo Flux)
    - <flux:navlist> com itens:
      - Dashboard (icon: chart-bar-square)
      - Inbox (icon: inbox) — com badge reativo de contagem
      - Kanban (icon: view-columns)
      - Daily (icon: calendar-days)
      - Projetos (icon: folder)
      - Tempo (icon: clock)
    - <flux:header> com:
      - Título da página atual
      - Slot para <livewire:global-timer /> (placeholder)
    - <flux:main> com {{ $slot }}
    - Dark mode always-on (classe .dark no <html>)
    - wire:navigate nos links
    - Auth middleware em todas as rotas

    Crie página placeholder ⚡dashboard.blade.php como SFC.
    Configure rota: Route::livewire('/', 'pages.dashboard')->name('dashboard')->middleware('auth');

Acceptance Criteria:
    - Layout renderiza com sidebar Flux colapsável + header + main
    - Navegação SPA-like com wire:navigate
    - Dark mode fixo
    - Protegido por auth

Arquivos:
    - resources/views/layouts/app.blade.php
    - resources/views/pages/⚡dashboard.blade.php
    - routes/web.php

Commit: "feat: base layout with collapsible Flux sidebar and SFC dashboard"
```
