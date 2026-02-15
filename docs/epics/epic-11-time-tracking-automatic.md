# EPIC 11: Status Time Tracking Automático

> **Sessão Claude Code:** Métricas passivas de tempo por coluna.
> **Prioridade:** #2 (baixo esforço, alto valor)
> **Estimativa:** ~45min · 2 tasks
> **Dependência:** Epic 10 (MCP) — mudanças de status via MCP disparam tracking automaticamente

---

## Contexto

Inspirado no Linear: rastrear automaticamente quanto tempo cada task passa em cada coluna (backlog, todo, doing, done). Sem ação do usuário — calcula a partir dos timestamps de mudança de status. Combinado com o timer manual, cria uma visão completa: "Essa task ficou 3 dias em backlog, 2h de timer ativo em doing."

---

## Task 11.1 — Model TaskStatusChange + Observer

```yaml
Prompt: |
    Crie a tabela e model para rastrear mudanças de status das tasks:

    Migration: task_status_changes
    - id, task_id (FK cascade), from_status (string nullable — null na criação),
      to_status (string), changed_at (datetime)

    Model: TaskStatusChange
    - Relationships: belongsTo(Task)
    - Scopes: forTask($taskId), forStatus($status)

    Observer: TaskObserver
    - Registre no AppServiceProvider
    - No evento 'updating': se o campo 'status' mudou, cria TaskStatusChange
      com from_status=old, to_status=new, changed_at=now
    - No evento 'created': cria TaskStatusChange com from_status=null,
      to_status=status atual, changed_at=now
    - O Observer dispara independente da origem da mudança (UI, MCP, artisan)

    Accessor no Task model:
    - timeInStatus(): array — retorna tempo acumulado em cada status
      Ex: ['inbox' => 120, 'backlog' => 4320, 'doing' => 180, 'done' => null]
      (em minutos)
    - currentStatusDuration(): int — minutos no status atual

    Atualizar Factory/Seeder para gerar TaskStatusChanges retroativos
    para tasks existentes no seed realista.

Acceptance Criteria:
    - Observer registra toda mudança de status (create + update)
    - timeInStatus() calcula corretamente para cada status
    - currentStatusDuration() retorna duração no status atual
    - Funciona tanto via UI (Livewire) quanto via MCP
    - Cascade delete: deletar task remove status_changes
    - Teste Feature: criar task, mudar status, verificar timeline

Arquivos:
    - database/migrations/*_create_task_status_changes_table.php
    - app/Models/TaskStatusChange.php
    - app/Observers/TaskObserver.php
    - app/Models/Task.php (atualizar com accessor e relationship)
    - app/Providers/AppServiceProvider.php (registrar observer)
    - database/seeders/DatabaseSeeder.php (atualizar)
    - tests/Feature/TaskStatusChangeTest.php

Commit: "feat: TaskStatusChange model with observer for automatic status time tracking"
```

---

## Task 11.2 — Visualização no Task Modal + Dashboard

```yaml
Prompt: |
    Adicione visualização do tempo por status:

    1. No Task Modal (⚡task-modal.blade.php):
    - Nova seção "Tempo por status" abaixo das TimeEntries
    - Barra horizontal segmentada mostrando tempo proporcional em cada status
    - Cores dos status (usar TaskStatus::color())
    - Hover mostra: "Backlog: 3 dias 2h" / "Doing: 4h 30min"
    - Usar <flux:tooltip> para os detalhes

    2. No Dashboard (⚡dashboard.blade.php):
    - Nova métrica: "Tempo médio em cada coluna" (últimos 30 dias)
    - Cards compactos mostrando média por status para tasks concluídas
    - Ex: "Backlog avg: 2.3 dias" / "Doing avg: 3.1h"

    3. No MCP GetTaskTool:
    - Incluir status_timeline no retorno do get-task
    - Array com from_status, to_status, changed_at, duration_minutes

Acceptance Criteria:
    - Barra de tempo por status renderiza no Task Modal
    - Tooltips mostram duração formatada
    - Dashboard mostra médias corretas
    - MCP retorna timeline no get-task
    - Teste Feature: verificar dados na visualização

Arquivos:
    - resources/views/components/⚡task-modal.blade.php (atualizar)
    - resources/views/pages/⚡dashboard.blade.php (atualizar)
    - app/Mcp/Tools/GetTaskTool.php (atualizar)
    - tests/Feature/StatusTimeVisualizationTest.php

Commit: "feat: status time tracking visualization in Task Modal and Dashboard"
```
