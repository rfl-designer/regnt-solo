# EPIC 3: Kanban Board

> **Sessão Claude Code:** Core visual do produto.
> **Estimativa:** ~45min · 2 tasks

---

## Task 3.1 — Página SFC: Kanban Board

```yaml
Prompt: |
    Crie o SFC resources/views/pages/⚡kanban.blade.php usando <flux:kanban> nativo:

    4 colunas: Backlog → Todo → Doing → Done
    - Done mostra apenas tasks da semana atual (segunda a domingo corrente)
    - Lazy loading: 20 tasks por coluna, botão "carregar mais"

    Filtros:
    - <flux:select> projeto (com "Todos")
    - <flux:select> prioridade
    - Toggle overdue (mostrar só atrasadas) — suportar query param ?overdue=1

    Cards:
    - Badge prioridade + badge estimativa (~30min) + badge overdue
    - Projeto: borda cor + emoji + nome
    - Timer rodando: ícone pulsing verde
    - Click → dispatch 'open-task-modal'

    Seção "Sem projeto":
    - Abaixo de cada coluna, separada visualmente
    - Só aparece se existem tasks sem projeto

    Comportamento ao mover para Done:
    - Para timer se rodando
    - Preenche completed_at
    - Marca done no DailyPlan de hoje (se existir)

    wire:sort para drag entre colunas (muda status + recalcula sort_order por coluna)

    Rota: Route::livewire('/kanban', 'pages.kanban')->name('kanban')->middleware('auth');

Acceptance Criteria:
    - 4 colunas renderizam com <flux:kanban>
    - Done mostra só semana corrente
    - Lazy loading 20 por coluna funciona
    - Filtros por projeto, prioridade e overdue funcionam
    - Seção "Sem projeto" aparece/esconde corretamente
    - Mover para Done: para timer + completed_at + DailyPlan sync
    - Teste Feature: tasks em colunas, mover, filtrar

Arquivos:
    - resources/views/pages/⚡kanban.blade.php
    - routes/web.php
    - tests/Feature/KanbanTest.php

Commit: "feat: Kanban board SFC with 4 columns, lazy loading, and rich filters"
```

---

## Task 3.2 — Componente SFC: Task Modal (Reativo)

```yaml
Prompt: |
    Crie o SFC resources/views/components/⚡task-modal.blade.php:

    Modal NÃO fecha ao salvar — fica aberta e dados atualizam reativo.

    Campos Flux:
    - <flux:input> título
    - <flux:editor> descrição (Flux Editor — markdown rich text)
    - <flux:select> projeto, prioridade, status
    - <flux:date-picker> prazo
    - <flux:input type="number"> estimativa (min)

    Seção TimeEntries (editável):
    - Lista de entries com started_at, stopped_at, duration, notes
    - Campos editáveis inline para ajustar horários e notas
    - Botão deletar entry individual

    Footer:
    - <flux:button> Salvar (não fecha modal)
    - <flux:button> Deletar (com modal de confirmação aninhado — sempre)
    - Toast de confirmação ao salvar

    Listener: 'open-task-modal' com taskId

Acceptance Criteria:
    - Modal abre via evento
    - Todos campos editáveis com Flux components
    - <flux:editor> para descrição markdown
    - Salvar persiste sem fechar modal (reativo)
    - TimeEntries editáveis inline
    - Deletar com confirmação modal
    - Fecha com Esc
    - Teste Feature: abrir, editar, salvar, verificar DB

Arquivos:
    - resources/views/components/⚡task-modal.blade.php
    - tests/Feature/TaskModalTest.php

Commit: "feat: TaskModal SFC reactive with Flux Editor and editable time entries"
```
