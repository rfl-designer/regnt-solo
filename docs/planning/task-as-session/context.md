# Documento de Contexto: Epic 15 — Task-as-Session

## 1. Resumo Executivo

Modelar tasks como sessoes de AI coding, onde **1 task = 1 sessao = 1 PR**. O epic e composto por duas tasks:

1. **Task 15.1 — Session fields na Task + Template**: Migration para adicionar `session_prompt` e `session_result` na tabela `tasks`, metodos no Model (`isSessionTask()`, `sessionSummary()`), factory state `->session()`, seeder com session data, atualizacao dos MCP Tools (CreateTaskTool, UpdateTaskTool, GetTaskTool) para aceitar/retornar session fields, e novo MCP Prompt `SessionPlanningPrompt`.
2. **Task 15.2 — Session View no Task Modal**: Layout especial no Task Modal para session tasks (exibindo Prompt, Resultado, Timeline), prefixo `>prompt` ou checkbox "Sessao de Dev" no Quick-Add, e badge 🤖 nos Kanban Cards para session tasks.

---

## 2. Requisitos

### Funcionais

#### Task 15.1 — Session Fields na Task + Template

- [ ] Migration `add_session_fields_to_tasks`: adicionar `session_prompt` (text, nullable) e `session_result` (text, nullable)
- [ ] `pr_url` ja existe na tabela — nao precisa de migration
- [ ] Model Task:
  - `isSessionTask(): bool` — retorna `true` se `session_prompt` nao e null
  - `sessionSummary(): array` — retorna array com prompt, result, pr_url, commits count, status
- [ ] Adicionar `session_prompt` e `session_result` ao `$fillable` do Task model
- [ ] Factory: novo state `->session()` que preenche `session_prompt` e `session_result` com dados fake
- [ ] Seeder: adicionar 2-3 tasks com status `doing`/`done` que tenham session data preenchido
- [ ] MCP Tools:
  - `CreateTaskTool`: aceitar `session_prompt` como parametro opcional
  - `UpdateTaskTool`: aceitar `session_prompt` e `session_result` como parametros opcionais
  - `GetTaskTool`: incluir `session_summary` no retorno (usando `sessionSummary()`)
- [ ] Novo MCP Prompt: `SessionPlanningPrompt` — gera contexto para planejar uma sessao de coding baseada no prompt da task, projeto, e tasks relacionadas

#### Task 15.2 — Session View no Task Modal

- [ ] Task Modal: detectar `isSessionTask()` e exibir layout especial:
  - Secao "Prompt da Sessao" com o `session_prompt` (read-only ou editavel)
  - Secao "Resultado" com o `session_result` (editavel)
  - Timeline: commits + status changes integrados cronologicamente
- [ ] Quick-Add: suportar prefixo `>` para criar session task (texto apos `>` vira `session_prompt`)
  - Alternativa: checkbox "Sessao de Dev" que habilita campo de prompt
- [ ] Kanban Card: exibir badge 🤖 quando `isSessionTask()` retorna true

### Nao-Funcionais

- Interface em PT-BR (labels, botoes, mensagens, toasts, empty states)
- Codigo em ingles (variaveis, classes, metodos)
- Dark mode only (sem light mode)
- SFC (Single-File Components) — sem arquivos PHP separados em `app/Livewire/`
- Testes Feature com Pest para toda logica de negocio
- Performance: eager loading adequado para evitar N+1
- Manter compatibilidade com tasks normais (nao-session) — tudo e opcional

---

## 3. Analise do Codebase

### Estrutura Relevante

```
app/
  Models/
    Task.php              # Model principal — sera modificado
                          # Fillable: project_id, title, description, status, priority,
                          #           due_date, estimated_minutes, completed_at, sort_order, pr_url
                          # Casts: status (TaskStatus), priority (TaskPriority), due_date, completed_at
                          # Relations: project(), commits(), timeEntries(), statusChanges(), dailyPlans()
                          # Scopes: inbox, active, byStatus, overdue, unassigned, doneThisWeek
                          # Metodos: isOverdue(), isRunning(), markAsDone(), commitCount(),
                          #          totalFilesChanged(), totalFocusMinutes()
                          # Accessors: time_in_status, current_status_duration
                          # Observer: TaskObserver
    TaskCommit.php        # Commits associados a tasks (hash, message, files_changed, etc.)
    Project.php           # Projetos com slug, emoji, color
    TimeEntry.php         # Entradas de tempo com focus sessions
    TaskStatusChange.php  # Historico de mudancas de status

  Enums/
    TaskStatus.php        # Inbox, Backlog, Todo, Doing, Done (com label, color, hexColor, icon)
    TaskPriority.php      # Urgent, High, Medium, Low (com label, color, icon)

  Mcp/
    Servers/
      SoloBoardServer.php # Server MCP — registra tools, resources, prompts
    Tools/
      CreateTaskTool.php  # Cria task — sera modificado (aceitar session_prompt)
      UpdateTaskTool.php  # Atualiza task — sera modificado (aceitar session_prompt/result)
      GetTaskTool.php     # Retorna task detalhada — sera modificado (incluir sessionSummary)
      DeleteTaskTool.php  # Deleta task
      ListTasksTool.php   # Lista tasks com filtros
      LogCommitsTool.php  # Registra commits em task
      StartTimerTool.php  # Inicia timer
      StopTimerTool.php   # Para timer
      TimerStatusTool.php # Status do timer
      TodayPlanTool.php   # Plano do dia
      SuggestTasksTool.php # Sugere tasks
      AddToPlanTool.php   # Adiciona ao plano
      ListProjectsTool.php # Lista projetos
    Prompts/
      DailyPlanningPrompt.php  # Prompt existente — referencia para SessionPlanningPrompt
    Resources/
      ProjectOverviewResource.php  # Resource existente

  Observers/
    TaskObserver.php      # Observer de Task

resources/views/
  components/
    ⚡task-modal.blade.php      # Modal de edicao — sera modificado (session view)
    ⚡task-quick-add.blade.php  # Quick-add — sera modificado (prefixo > ou checkbox)
    ⚡timer-notes-modal.blade.php
  pages/
    ⚡kanban.blade.php          # Kanban board — sera modificado (badge 🤖)
    ⚡dashboard.blade.php
    ⚡inbox.blade.php
    ⚡daily-planner.blade.php
    ⚡projects.blade.php
    ⚡project-detail.blade.php
    ⚡time-report.blade.php
    ⚡weekly-review.blade.php

database/
  factories/
    TaskFactory.php       # Factory — sera modificado (state session)
  seeders/
    DatabaseSeeder.php    # Seeder — sera modificado (session tasks)
  migrations/
    2026_02_13_110225_create_tasks_table.php
    2026_02_15_134345_create_task_commits_table.php
    2026_02_15_134346_add_pr_url_to_tasks_table.php

tests/Feature/
  Mcp/
    TaskToolsTest.php           # Testes MCP tools — sera modificado
    ResourceAndPromptTest.php   # Testes MCP prompts — referencia para SessionPlanningPrompt
    LogCommitsToolTest.php
    TimerToolsTest.php
    PlanAndProjectToolsTest.php
    McpAuthTest.php
  TaskTest.php                  # Testes do model Task
  TaskModalTest.php             # Testes do modal
  TaskQuickAddTest.php          # Testes do quick-add
  TaskStatusChangeTest.php
```

### Schema Atual da Tabela `tasks`

| Coluna | Tipo | Nullable | Default | Notas |
|--------|------|----------|---------|-------|
| id | integer | no | auto_increment | PK |
| project_id | integer | yes | null | FK → projects.id (on delete set null) |
| title | varchar | no | — | |
| description | text | yes | null | |
| status | varchar | no | 'inbox' | Enum: inbox, backlog, todo, doing, done |
| priority | varchar | no | 'medium' | Enum: urgent, high, medium, low |
| due_date | date | yes | null | |
| estimated_minutes | integer | yes | null | |
| completed_at | datetime | yes | null | |
| sort_order | integer | no | 0 | |
| pr_url | varchar | yes | null | Ja existe! |
| created_at | datetime | yes | null | |
| updated_at | datetime | yes | null | |

**Indices**: primary (id), tasks_due_date_index, tasks_priority_index, tasks_project_id_index, tasks_status_index

### Padroes Identificados

1. **SFC Format**: `<?php ... new class extends Component { ... } ?> <div>...</div>` — PHP no topo, Blade embaixo
2. **Computed Properties**: `#[Computed]` para queries reativas com `unset($this->prop)` para invalidar cache
3. **Eventos Livewire**: `$this->dispatch('event-name')` para comunicacao entre componentes
4. **Listeners**: `#[On('event-name')]` para escutar eventos
5. **Flux Toast**: `Flux::toast(variant: 'success', heading: '...', text: '...')` para feedback
6. **Flux Modal**: `wire:model.self` para controle de estado
7. **MCP Tool Pattern**:
   - Classe extends `Tool` com `$name`, `$description`
   - `handle(Request $request): Response` com `$request->validate()`
   - `schema(JsonSchema $schema): array` para definir input schema
   - Annotations: `#[IsReadOnly]`, `#[IsIdempotent]`
   - Retorno: `Response::text(json_encode($data, JSON_PRETTY_PRINT))`
8. **MCP Prompt Pattern**:
   - Classe extends `Prompt` com `$description`
   - `arguments(): array` retorna `Argument[]`
   - `handle(Request $request): array` retorna `Response[]`
   - Usa `Response::text($message)->asAssistant()` para system message
   - Usa `Response::text($context)` para user message
9. **MCP Server Registration**: Tools, Resources e Prompts registrados em arrays no `SoloBoardServer`
10. **MCP Test Pattern**: `SoloBoardServer::tool(ToolClass::class, [...])` com `assertOk()`, `assertSee()`, `assertHasErrors()`
11. **Factory States**: Metodos fluentes como `->done()`, `->doing()`, `->todo()`, `->backlog()`, `->overdue()`
12. **Seeder**: Tasks criadas com `Task::withoutEvents()` para evitar observer, dados realistas em PT-BR
13. **Quick-Add Prefixes**: `#projeto`, `!prioridade`, `@data` — padrao para adicionar `>prompt`
14. **Kanban Card Badges**: `<flux:badge>` com size="sm", color, icon — padrao para badge 🤖
15. **PT-BR**: Labels e mensagens em portugues, codigo em ingles
16. **Dark mode**: Cores `zinc-700/800/900`, sem light mode

### Codigo Existente Reutilizavel

| Artefato | Uso no Epic 15 |
|----------|----------------|
| `Task::commits()` | Contar commits na sessionSummary |
| `Task::pr_url` | Ja existe, usado na sessionSummary |
| `Task::commitCount()` | Reutilizar na sessionSummary |
| `Task::totalFilesChanged()` | Reutilizar na sessionSummary |
| `DailyPlanningPrompt` | Referencia de padrao para SessionPlanningPrompt |
| `CreateTaskTool::schema()` | Padrao para adicionar session_prompt |
| `GetTaskTool::handle()` | Padrao para incluir sessionSummary |
| Quick-Add prefix detection | Padrao regex para adicionar `>` prefix |
| Kanban card badge pattern | Padrao para adicionar badge 🤖 |

---

## 4. Dependencias

### Externas (ja instaladas — nenhuma nova necessaria)

| Pacote | Versao | Uso |
|--------|--------|-----|
| `laravel/framework` | 12.51.0 | Migration, Model, Eloquent |
| `laravel/mcp` | 0.5.7 | MCP Tools, Prompts, Server |
| `livewire/livewire` | 4.1.4 | SFC, eventos, reatividade |
| `livewire/flux-pro` | 2.12.0 | Modal, Input, Badge, Editor |
| `tailwindcss` | 4.1.18 | Estilizacao |
| `pestphp/pest` | 4.3.2 | Testes Feature |

### Internas (ja existem)

| Artefato | Caminho | Necessario para |
|----------|---------|-----------------|
| Model Task | `app/Models/Task.php` | Adicionar metodos e fillable |
| Model TaskCommit | `app/Models/TaskCommit.php` | Dados de commits na sessionSummary |
| Enum TaskStatus | `app/Enums/TaskStatus.php` | Status na sessionSummary |
| Factory TaskFactory | `database/factories/TaskFactory.php` | State session |
| Seeder DatabaseSeeder | `database/seeders/DatabaseSeeder.php` | Session tasks |
| SoloBoardServer | `app/Mcp/Servers/SoloBoardServer.php` | Registrar SessionPlanningPrompt |
| CreateTaskTool | `app/Mcp/Tools/CreateTaskTool.php` | Aceitar session_prompt |
| UpdateTaskTool | `app/Mcp/Tools/UpdateTaskTool.php` | Aceitar session_prompt/result |
| GetTaskTool | `app/Mcp/Tools/GetTaskTool.php` | Retornar sessionSummary |
| DailyPlanningPrompt | `app/Mcp/Prompts/DailyPlanningPrompt.php` | Referencia de padrao |
| Task Modal | `resources/views/components/⚡task-modal.blade.php` | Session view |
| Quick-Add | `resources/views/components/⚡task-quick-add.blade.php` | Prefixo > |
| Kanban | `resources/views/pages/⚡kanban.blade.php` | Badge 🤖 |

### Epics Pre-Requisitos (ja implementados)

| Epic | Status | Evidencia |
|------|--------|-----------|
| Epic 10 — MCP Server | **Implementado** | `SoloBoardServer` com 13 tools, 1 resource, 1 prompt; testes em `tests/Feature/Mcp/` |
| Epic 13 — Git Integration | **Implementado** | `TaskCommit` model, `LogCommitsTool`, `pr_url` na tabela tasks, commits no Task Modal |
| Epic 3 — Kanban Board | **Implementado** | `⚡kanban.blade.php` com drag-and-drop, filtros, badges |
| Epic 3 — Task Modal | **Implementado** | `⚡task-modal.blade.php` com edicao completa, time entries, git section |
| Epic 2 — Quick-Add | **Implementado** | `⚡task-quick-add.blade.php` com prefixos #, !, @ |

---

## 5. Riscos e Mitigacoes

| # | Risco | Probabilidade | Impacto | Mitigacao |
|---|-------|---------------|---------|-----------|
| 1 | **Compatibilidade com tasks normais** — Adicionar session fields nao deve quebrar tasks existentes que nao sao sessions | Baixa | Alto | Campos `session_prompt` e `session_result` sao nullable. `isSessionTask()` retorna false quando `session_prompt` e null. Toda logica de session e condicional. |
| 2 | **Migration em SQLite** — SQLite tem limitacoes com ALTER TABLE, mas adicionar colunas nullable funciona | Baixa | Baixo | Adicionar colunas nullable e seguro em SQLite. Nao precisa de `->change()`. |
| 3 | **Complexidade do Task Modal** — O modal ja e grande (517 linhas). Adicionar session view pode tornar complexo demais | Media | Medio | Usar `@if($this->isSessionTask)` para renderizar condicionalmente a session view. Manter a logica de session separada visualmente no Blade. Considerar extrair para partial se ficar muito grande. |
| 4 | **Prefixo `>` no Quick-Add pode conflitar** — O `>` e usado em markdown para blockquotes | Baixa | Baixo | O Quick-Add nao usa markdown. O `>` so e detectado no inicio do input ou como prefixo isolado. Regex: `/^>(.+)/` ou `/>\s*(.+)/`. |
| 5 | **SessionPlanningPrompt precisa de contexto rico** — O prompt precisa de dados do projeto, tasks relacionadas, e historico | Media | Medio | Reutilizar patterns do `DailyPlanningPrompt`. Carregar task com relations (project, commits, statusChanges). Incluir contexto do projeto se task tem projeto. |
| 6 | **Seeder com session data pode falhar** — Se a migration nao rodou antes do seeder | Baixa | Baixo | A migration sera criada antes do seeder ser atualizado. Em `php artisan migrate:fresh --seed`, a ordem e garantida. |
| 7 | **Performance do sessionSummary()** — Se chamado em listas, pode causar N+1 | Media | Medio | `sessionSummary()` so e chamado no `GetTaskTool` (single task) e no Task Modal (single task). Nao e usado em listas. Eager load `commits` quando necessario. |

---

## 6. Decisoes Tecnicas

### 6.1 Migration — Campos Session

```php
Schema::table('tasks', function (Blueprint $table) {
    $table->text('session_prompt')->nullable()->after('pr_url');
    $table->text('session_result')->nullable()->after('session_prompt');
});
```

**Nota**: Usar `text` (nao `string/varchar`) porque prompts e resultados podem ser longos.

### 6.2 Model Task — Novos Metodos

```php
// isSessionTask() — simples check de session_prompt
public function isSessionTask(): bool
{
    return $this->session_prompt !== null;
}

// sessionSummary() — array com dados da sessao
/** @return array{is_session: bool, prompt: string|null, result: string|null, pr_url: string|null, commits_count: int, files_changed: int, status: string} */
public function sessionSummary(): array
{
    return [
        'is_session' => $this->isSessionTask(),
        'prompt' => $this->session_prompt,
        'result' => $this->session_result,
        'pr_url' => $this->pr_url,
        'commits_count' => $this->commitCount(),
        'files_changed' => $this->totalFilesChanged(),
        'status' => $this->status->value,
    ];
}
```

### 6.3 Factory State

```php
public function session(): static
{
    return $this->state(fn (array $attributes) => [
        'session_prompt' => fake()->paragraph(),
        'session_result' => fake()->optional(0.7)->paragraph(),
    ]);
}
```

### 6.4 MCP Tools — Alteracoes

**CreateTaskTool**: Adicionar `session_prompt` ao schema e validation. Se `session_prompt` e fornecido, a task e criada como session task.

**UpdateTaskTool**: Adicionar `session_prompt` e `session_result` ao schema e validation. Permitir atualizar ambos.

**GetTaskTool**: Incluir `session_summary` no retorno usando `$task->sessionSummary()`.

### 6.5 SessionPlanningPrompt

Seguir o padrao do `DailyPlanningPrompt`:
- Argumento: `task_id` (required) — ID da task session para planejar
- Contexto: prompt da task, projeto, tasks relacionadas do mesmo projeto, commits existentes
- System message: instrucoes para o AI planejar a sessao de coding
- User message: contexto formatado

### 6.6 Task Modal — Session View

Condicional no Blade:
```blade
@if ($this->isSessionTask)
    {{-- Session-specific sections --}}
    <div class="space-y-3">
        <flux:heading size="sm">Prompt da Sessao</flux:heading>
        <div class="rounded-lg border border-zinc-700 bg-zinc-800/50 p-3">
            <flux:text>{{ $sessionPrompt }}</flux:text>
        </div>
    </div>

    @if ($sessionResult)
        <div class="space-y-3">
            <flux:heading size="sm">Resultado</flux:heading>
            <flux:editor wire:model="sessionResult" ... />
        </div>
    @endif
@endif
```

### 6.7 Quick-Add — Prefixo `>`

Adicionar deteccao do prefixo `>` no `createTask()`:
```php
// Detectar session prompt
$sessionPrompt = null;
if (str_starts_with($input, '>')) {
    $sessionPrompt = trim(substr($input, 1));
    // O titulo sera derivado do prompt (primeiras palavras)
}
```

### 6.8 Kanban Card — Badge 🤖

Adicionar badge condicional no card:
```blade
@if ($task->isSessionTask())
    <flux:badge size="sm" color="violet">
        🤖 Sessao
    </flux:badge>
@endif
```

**Nota**: Precisa que `isSessionTask()` funcione sem eager loading extra (apenas checa `session_prompt !== null`).

---

## 7. Escopo

### Incluido

**Task 15.1:**
- Migration `add_session_fields_to_tasks`
- Model Task: `isSessionTask()`, `sessionSummary()`, fillable atualizado
- Factory: state `->session()`
- Seeder: 2-3 tasks com session data
- MCP Tools: CreateTaskTool, UpdateTaskTool, GetTaskTool atualizados
- MCP Prompt: `SessionPlanningPrompt`
- SoloBoardServer: registrar `SessionPlanningPrompt`
- Testes: model, factory, MCP tools, MCP prompt

**Task 15.2:**
- Task Modal: session view condicional
- Quick-Add: prefixo `>` para session tasks
- Kanban Card: badge 🤖
- Testes: modal session view, quick-add com `>`, kanban badge

### Excluido

- Integracao real com AI/LLM para executar sessoes (futuro)
- Criacao automatica de PR (futuro)
- Workflow automatizado de session (futuro)
- Notificacoes de session concluida (futuro)
- Dashboard de metricas de sessions (futuro)
- Edicao do `session_prompt` apos criacao (pode ser adicionado depois)

---

## 8. Arquivos a Criar/Modificar

### Criar

| Arquivo | Tipo | Descricao |
|---------|------|-----------|
| `database/migrations/XXXX_add_session_fields_to_tasks_table.php` | Migration | Adiciona session_prompt e session_result |
| `app/Mcp/Prompts/SessionPlanningPrompt.php` | MCP Prompt | Prompt para planejar sessao de coding |
| `tests/Feature/TaskSessionTest.php` | Teste | Testes do model (isSessionTask, sessionSummary) |
| `tests/Feature/Mcp/SessionPlanningPromptTest.php` | Teste | Testes do MCP prompt |

### Modificar

| Arquivo | Alteracao |
|---------|-----------|
| `app/Models/Task.php` | Adicionar `session_prompt`, `session_result` ao `$fillable`; adicionar `isSessionTask()` e `sessionSummary()` |
| `database/factories/TaskFactory.php` | Adicionar state `->session()` |
| `database/seeders/DatabaseSeeder.php` | Adicionar 2-3 tasks com session data |
| `app/Mcp/Tools/CreateTaskTool.php` | Aceitar `session_prompt` no schema e validation |
| `app/Mcp/Tools/UpdateTaskTool.php` | Aceitar `session_prompt` e `session_result` no schema e validation |
| `app/Mcp/Tools/GetTaskTool.php` | Incluir `session_summary` no retorno |
| `app/Mcp/Servers/SoloBoardServer.php` | Registrar `SessionPlanningPrompt` no array `$prompts` |
| `resources/views/components/⚡task-modal.blade.php` | Adicionar session view condicional (prompt, resultado) |
| `resources/views/components/⚡task-quick-add.blade.php` | Adicionar deteccao de prefixo `>` e criacao de session task |
| `resources/views/pages/⚡kanban.blade.php` | Adicionar badge 🤖 nos cards de session tasks |
| `tests/Feature/Mcp/TaskToolsTest.php` | Adicionar testes para session fields nos tools |
| `tests/Feature/TaskModalTest.php` | Adicionar testes para session view no modal |
| `tests/Feature/TaskQuickAddTest.php` | Adicionar testes para prefixo `>` |

---

## 9. Proximos Passos

1. **Aprovar este documento de contexto**
2. **Encaminhar para task-breakdown** para criacao das User Stories detalhadas
3. **Implementar Task 15.1** — Backend (migration, model, factory, seeder, MCP tools, MCP prompt)
4. **Implementar Task 15.2** — Frontend (task modal, quick-add, kanban badge)
5. **Rodar testes** e validar tudo funciona
6. **Rodar Pint** para formatacao (`vendor/bin/pint --dirty --format agent`)
