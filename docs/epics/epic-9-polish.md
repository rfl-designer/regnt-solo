# EPIC 9: Keyboard Shortcuts, Command Palette & Polish

> **Sessão Claude Code:** UX final.
> **Estimativa:** ~45min · 3 tasks

---

## Task 9.1 — Keyboard Shortcuts

```yaml
Prompt: |
    Implemente atalhos globais no layout usando Alpine.js:

    - N → dispatch 'open-quick-add' (abre modal overlay)
    - K → wire:navigate /kanban
    - D → wire:navigate /daily
    - I → wire:navigate /inbox
    - T → dispatch 'toggle-timer'
    - Escape → fechar qualquer modal Flux
    - Cmd+K / Ctrl+K → dispatch 'open-command-palette'

    Ignorar quando foco em input/textarea/select/[contenteditable].

    REMOVIDO: atalhos 1-4 (mover task entre colunas).

Acceptance Criteria:
    - Todos atalhos funcionam
    - Não interfere com digitação
    - Esc fecha modals

Arquivos:
    - resources/views/layouts/app.blade.php

Commit: "feat: global keyboard shortcuts"
```

---

## Task 9.2 — Command Palette com Ações Rápidas

```yaml
Prompt: |
    Crie o SFC resources/views/components/⚡command-palette.blade.php:

    - Ativado por Cmd+K
    - <flux:modal> overlay com <flux:input> de busca
    - Debounce 300ms

    Dois modos:
    1. Busca normal (sem prefixo): busca tasks e projetos, Enter/click navega
    2. Sintaxe de comando (prefixo >):
       - > mover [task] [status]
       - > timer [task]
       - > deletar [task] (com confirmação)
       - > projeto [task] [projeto]
       - > prioridade [task] [nivel]
       - > planejar [task] (adicionar ao plano de hoje)

    Resultados agrupados com ícones.
    Labels em PT-BR.

Acceptance Criteria:
    - Busca funciona (tasks + projetos)
    - Todos os 6 comandos > funcionam
    - Navegação e ações executam corretamente
    - Teste Feature: buscar, executar comando

Arquivos:
    - resources/views/components/⚡command-palette.blade.php
    - tests/Feature/CommandPaletteTest.php

Commit: "feat: command palette SFC with prefixed action commands"
```

---

## Task 9.3 — Seed Realista

```yaml
Prompt: |
    Atualize DatabaseSeeder para dados realistas:
    - Usuário: email/senha do .env (SOLO_USER_EMAIL, SOLO_USER_PASSWORD)
    - 6 projetos: "API Gateway", "Landing Page v2", "Mobile App",
                  "Admin Dashboard", "Design System", "Blog Pessoal"
    - 35 tasks distribuídas entre projetos e inbox (variando status/prioridade)
      - Algumas com estimated_minutes preenchido
      - Algumas com completed_at (status done)
    - TimeEntries nos últimos 14 dias (1-4h/dia variando)
    - 1 DailyPlan para hoje com 5 tasks
    - 1 timer rodando em uma task "doing"

Acceptance Criteria:
    - migrate:fresh --seed popula dados + usuário
    - Login funciona com credenciais do .env
    - Dashboard mostra métricas não-zero
    - Kanban tem cards em todas colunas
    - Timer global mostra timer ativo

Arquivos:
    - database/seeders/DatabaseSeeder.php
    - database/factories/*

Commit: "chore: realistic seed data with auth user from .env"
```
