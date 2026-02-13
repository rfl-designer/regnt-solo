# EPIC 4: Daily Planner

> **Sessão Claude Code:** Planejamento do dia.
> **Estimativa:** ~30min · 1 task

---

## Task 4.1 — Página SFC: Daily Planner

```yaml
Prompt: |
    Crie o SFC resources/views/pages/⚡daily-planner.blade.php:

    Header:
    - <flux:date-picker wire:model.live="date" />
    - Barra de progresso (completionRate)

    Carry-over banner (só no plano de hoje):
    - Se ontem tinha tasks não completadas:
      "Você tem X tasks de ontem. Mover para hoje?"
    - Botão "Mover para hoje" copia tasks incompletas

    Planos de dias passados: read-only (só botão "Mover para hoje" em tasks incompletas)

    Coluna esquerda "Plano do dia" (wire:sort):
    - <flux:checkbox> + título + projeto badge + botão remover
    - Checkbox muda status global para done (+ completed_at + para timer se rodando)
    - Tasks completadas: visual riscado

    Coluna direita "Tasks disponíveis":
    - Qualquer task ativa (backlog, todo, doing, inbox)
    - Botão "+" para adicionar ao plano

    Footer:
    - <flux:textarea> notas do dia (debounce 500ms)

    Rota: Route::livewire('/daily', 'pages.daily-planner')->name('daily')->middleware('auth');

Acceptance Criteria:
    - Plano carrega/cria automaticamente para a data
    - Carry-over banner funciona
    - Checkbox muda status global + completed_at
    - Timer para ao completar via checkbox
    - wire:sort reordena
    - Tasks disponíveis incluem todos status ativos
    - Planos passados são read-only (exceto "mover para hoje")
    - Teste Feature: criar plano, adicionar tasks, completar, carry-over

Arquivos:
    - resources/views/pages/⚡daily-planner.blade.php
    - routes/web.php
    - tests/Feature/DailyPlannerTest.php

Commit: "feat: Daily Planner SFC with carry-over banner and global done sync"
```
