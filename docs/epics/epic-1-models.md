# EPIC 1: Modelos & Migrations

> **Sessão Claude Code:** Criar toda a camada de dados.
> **Estimativa:** ~45min · 4 tasks

---

## Task 1.1 — Migration e Model: Project

```yaml
Prompt: |
    Crie a migration e o model Project com:
    - Fields: name, slug (unique), color (hex, default '#6366f1'), emoji (default '📋'),
      status (string, default 'active'), priority (string, default 'medium'),
      description (text nullable)
    - PHP Enums: App\Enums\ProjectStatus (active, paused, archived),
      App\Enums\ProjectPriority (high, medium, low)
    - Cada enum com métodos: label() (PT-BR), color(), icon()
    - Casts para enums
    - Scopes: active(), paused(), archived(), ordered() (priority desc, name asc)
    - Fillable, relationship: hasMany(Task)
    - Ao arquivar: apenas muda status (tasks ficam onde estão, ocultas do Kanban)
    - Factory + Seeder com 5 projetos

Acceptance Criteria:
    - Migration roda sem erros
    - Model tem casts, scopes, relationships
    - Enums com labels em PT-BR
    - Factory gera projetos válidos
    - Teste Feature: criar projeto, verificar scopes

Arquivos:
    - database/migrations/*_create_projects_table.php
    - app/Models/Project.php
    - app/Enums/ProjectStatus.php, app/Enums/ProjectPriority.php
    - database/factories/ProjectFactory.php
    - tests/Feature/ProjectTest.php

Commit: "feat: Project model with enums, scopes, and factory"
```

---

## Task 1.2 — Migration e Model: Task

```yaml
Prompt: |
    Crie a migration e o model Task com:
    - Fields: project_id (nullable FK), title, description (text nullable),
      status (string, default 'inbox'), priority (string, default 'medium'),
      due_date (date nullable), estimated_minutes (integer nullable),
      completed_at (datetime nullable), sort_order (integer default 0)
    - sort_order é POR COLUNA (por status) — resetado ao mudar coluna
    - PHP Enums: App\Enums\TaskStatus (inbox, backlog, todo, doing, done),
      App\Enums\TaskPriority (urgent, high, medium, low)
    - Cada enum com métodos: label() (PT-BR), color(), icon()
    - Casts para enums + completed_at como datetime
    - Scopes: inbox(), active() (não done), byStatus($status), overdue(),
      unassigned() (project_id null), doneThisWeek() (seg-dom corrente)
    - Relationships: belongsTo(Project), hasMany(TimeEntry), belongsToMany(DailyPlan)
    - Helpers: isOverdue(): bool, isRunning(): bool, markAsDone(): void
    - markAsDone() deve: setar status=done, completed_at=now(), parar timer se rodando
    - Factory + seeder com 20 tasks distribuídas
    - Cascade delete: TimeEntries deletadas junto com a task

Acceptance Criteria:
    - Migration roda, FK nullable para projects
    - Task com project_id=null é válida (inbox)
    - completed_at preenchido ao marcar done
    - sort_order funciona por coluna
    - Scopes funcionam, incluindo doneThisWeek
    - Teste Feature: criar task, testar scopes, isOverdue, markAsDone

Arquivos:
    - database/migrations/*_create_tasks_table.php
    - app/Models/Task.php
    - app/Enums/TaskStatus.php, app/Enums/TaskPriority.php
    - database/factories/TaskFactory.php
    - tests/Feature/TaskTest.php

Commit: "feat: Task model with enums, scopes, completed_at, and per-column sort_order"
```

---

## Task 1.3 — Migration e Model: TimeEntry

```yaml
Prompt: |
    Crie a migration e o model TimeEntry com:
    - Fields: task_id (FK cascade delete), started_at (datetime),
      stopped_at (datetime nullable), notes (text nullable)
    - Accessor: duration_minutes — diferença em minutos, ou desde started_at se running
    - Scopes: running(), forDate($date), forWeek(), forProject($projectId via task)
    - Relationship: belongsTo(Task)
    - Static: stopAllRunning() — stop qualquer timer rodando (preenche stopped_at=now)
    - Timer abandonado: não tratar (responsabilidade do usuário)
    - Factory

Acceptance Criteria:
    - duration_minutes calcula corretamente (running e stopped)
    - running() scope retorna entries ativas
    - stopAllRunning() para todos os timers
    - Cascade delete funciona (deletar task remove entries)
    - Teste Feature: start, stop, verificar duração

Arquivos:
    - database/migrations/*_create_time_entries_table.php
    - app/Models/TimeEntry.php
    - database/factories/TimeEntryFactory.php
    - tests/Feature/TimeEntryTest.php

Commit: "feat: TimeEntry model with duration accessor and scopes"
```

---

## Task 1.4 — Migration e Model: DailyPlan + Pivot

```yaml
Prompt: |
    Crie migrations e models:
    - DailyPlan: date (date unique), notes (text nullable)
    - Pivot daily_plan_task: daily_plan_id, task_id, sort_order (int),
      completed_at (datetime nullable)
    - DailyPlan relationships:
      belongsToMany(Task)->withPivot('sort_order','completed_at')->withTimestamps()
    - Helpers: getOrCreateForDate($date), completionRate(),
      incompleteTasks() (tasks do plano sem completed_at no pivot)
    - Task: belongsToMany(DailyPlan)

Acceptance Criteria:
    - Pivot persiste sort_order e completed_at
    - getOrCreateForDate funciona
    - completionRate calcula % correto
    - incompleteTasks retorna tasks não completadas
    - Teste Feature: plano com tasks, completar, verificar rate

Arquivos:
    - database/migrations/*_create_daily_plans_table.php
    - database/migrations/*_create_daily_plan_task_table.php
    - app/Models/DailyPlan.php
    - tests/Feature/DailyPlanTest.php

Commit: "feat: DailyPlan model with pivot and helpers"
```
