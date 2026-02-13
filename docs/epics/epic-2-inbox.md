# EPIC 2: Inbox & Captura Rápida

> **Sessão Claude Code:** Primeira feature visível.
> **Estimativa:** ~30min · 2 tasks

---

## Task 2.1 — Componente SFC: TaskQuickAdd (Modal Overlay)

```yaml
Prompt: |
  Crie o SFC resources/views/components/⚡task-quick-add.blade.php:

  Modal overlay ativado por hotkey N de qualquer tela.
  - <flux:modal name="quick-add">
  - <flux:input> com wire:model="title"
  - Sintaxe inline:
    - #slug → associar projeto (dropdown autocomplete ao digitar #)
    - !prioridade → definir prioridade (autocomplete: urgent, high, medium, low)
    - @data → definir due_date (autocomplete: @hoje, @amanha, @segunda, @proxima-semana)
  - Autocomplete para todos os 3 prefixos
  - Se #slug não existe: toast aviso "Projeto #xyz não encontrado", cria task sem projeto
  - Sempre cria com status=inbox (independente da tela atual)
  - Enter para criar, Esc para fechar
  - Toast de confirmação

  Incluir no layout app.blade.php para estar disponível em todas as páginas.

Acceptance Criteria:
  - Modal abre via hotkey N
  - #projeto parseia e autocomplete funciona
  - !prioridade autocomplete funciona
  - @data autocomplete funciona (hoje, amanha, segunda, proxima-semana)
  - #slug inválido: toast aviso + cria task sem projeto
  - Input limpa após criar
  - Teste Feature: criar task com sintaxe completa

Arquivos:
  - resources/views/components/⚡task-quick-add.blade.php
  - resources/views/layouts/app.blade.php (incluir componente)
  - tests/Feature/TaskQuickAddTest.php

Commit: "feat: TaskQuickAdd modal overlay with rich inline syntax and autocomplete"
```

---

## Task 2.2 — Página SFC: Inbox

```yaml
Prompt: |
    Crie o SFC resources/views/pages/⚡inbox.blade.php:

    - Lista tasks inbox com project relationship, ordenadas por latest
    - Cada card: título, tempo desde criação, projeto (se tiver)
    - Ações por task:
      - <flux:select> para atribuir projeto (inline, compact)
      - <flux:button> "→ Backlog" para mover
      - <flux:button> delete com <flux:modal> de confirmação
    - Empty state: ícone inbox + "Nenhuma task no inbox. Pressione N para criar."
    - Badge do inbox na sidebar atualiza via evento reativo

    Rota: Route::livewire('/inbox', 'pages.inbox')->name('inbox')->middleware('auth');

Acceptance Criteria:
    - Lista tasks inbox corretamente
    - Mover para backlog atualiza status
    - Atribuir projeto funciona
    - Delete com confirmação modal
    - Empty state aparece quando vazio
    - Badge sidebar atualiza ao criar/mover task

Arquivos:
    - resources/views/pages/⚡inbox.blade.php
    - routes/web.php
    - tests/Feature/InboxTest.php

Commit: "feat: Inbox page SFC with triage actions and reactive badge"
```
