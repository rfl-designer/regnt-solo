# SoloBoard

> Sistema de gestão de projetos pessoal para desenvolvedor solo.

## Idioma

- **Interface** (labels, botões, mensagens, toasts, empty states): Português (PT-BR)
- **Código** (variáveis, classes, métodos, commits): Inglês

## Componentes — REGRA PRINCIPAL

- TODOS os componentes Livewire são **Single-File Components (SFC)**
- Formato: `@php new class extends Livewire\Component { } @endphp` seguido do template Blade
- NÃO criar arquivos PHP separados em `app/Livewire/`
- Pages: `resources/views/pages/⚡nome.blade.php`
- Components: `resources/views/components/⚡nome.blade.php`
- Rotas: `Route::livewire()` em `routes/web.php`

## Flux UI — SEMPRE USAR

- Docs: https://fluxui.dev/docs
- Layout: `<flux:sidebar>` (colapsável), `<flux:header>`, `<flux:main>`
- Kanban: `<flux:kanban>`, `<flux:kanban.column>`, `<flux:kanban.card>`
- Editor: `<flux:editor>` (markdown rich text para descrição de tasks)
- Forms: `<flux:input>`, `<flux:select>`, `<flux:textarea>`, `<flux:checkbox>`
- Modals: `<flux:modal name="...">`
- Charts: `<flux:chart>`, `<flux:chart.line>`, `<flux:chart.bar>`
- Tables: `<flux:table>`
- Feedback: `<flux:toast>`
- Data: `<flux:date-picker>`
- Drag-and-drop: `wire:sort` (Livewire v4 nativo), NÃO usar SortableJS

## Auth

- Breeze Livewire, registro desabilitado
- Usuário criado via seeder (credenciais no `.env`: `SOLO_USER_EMAIL`, `SOLO_USER_PASSWORD`)
- Dados globais (sem `user_id` nos models — single-user)
- Todas as rotas com `middleware('auth')`

## Models & Enums

- Models: `App\Models\` — Project, Task, TimeEntry, DailyPlan, TaskStatusChange, WeeklyReview, RecurringTask, TaskTemplate
- Enums: `App\Enums\` (PHP native enums) — cada enum implementa `label()` (PT-BR), `color()`, `icon()`
- `Task.completed_at`: preenchido ao marcar done
- `Task.sort_order`: por coluna (por status)
- `TimeEntry`: cascade delete com Task
- `TaskStatusChange`: cascade delete com Task — registra automaticamente cada mudança de status
- Sem subtasks — tasks são flat, descrição markdown via `<flux:editor>`

## Decisões de Design

- **Dark mode only** (sem toggle, sem light mode)
- **Kanban**: 4 colunas (Backlog → Todo → Doing → Done). Done mostra só semana corrente
- **Kanban**: lazy loading 20 por coluna, seção "Sem projeto" separada
- **Daily Planner**: checkbox muda status GLOBAL para done, carry-over banner para tasks de ontem
- **Timer**: apenas 1 ativo por vez, mini modal de notas ao parar (bloqueia)
- **Task Modal**: NÃO fecha ao salvar (reativa), TimeEntries editáveis inline, barra de tempo por status
- **Quick-Add**: modal overlay (hotkey `Ctrl+N`), sempre cria inbox, autocomplete `#projeto` `!prioridade` `@data`
- **Command Palette** (`Ctrl+K`): busca + 6 comandos com prefixo `>`
- **Dashboard cards**: clicáveis, navegam para páginas filtradas + métricas de tempo médio por status
- **Empty states**: ícone + texto + CTA + dica de atalho

## Padrões Visuais

### Cards e Containers

| Tipo | Classes Tailwind | Uso |
|------|------------------|-----|
| **Container** | `rounded-xl border border-zinc-700 bg-zinc-900/50` | Seções principais, colunas, painéis |
| **Item** | `rounded-lg border border-zinc-700 bg-zinc-800` | Cards de task, itens de lista, linhas |
| **Accent** | `rounded-xl border border-{cor}-500/20 bg-zinc-800/50` | Cards de métricas com destaque colorido |
| **Interactive** | `hover:border-zinc-500` ou `hover:border-{cor}-500/40` | Estados de hover |

### Sistema de Cores

#### Status (TaskStatus)

| Status | Cor | Hex | Uso |
|--------|-----|-----|-----|
| Inbox | `zinc` | `#a1a1aa` | Tasks não triadas |
| Backlog | `slate` | `#94a3b8` | Tasks para depois |
| Todo | `blue` | `#60a5fa` | Tasks prontas para fazer |
| Doing | `amber` | `#fbbf24` | Tasks em progresso |
| Done | `emerald` | `#34d399` | Tasks concluídas |

#### Prioridade (TaskPriority)

| Prioridade | Cor | Ícone | Uso |
|------------|-----|-------|-----|
| Urgent | `red` | `exclamation-circle` | Crítico, resolver imediatamente |
| High | `orange` | `arrow-up` | Alta prioridade |
| Medium | `blue` | `minus` | Prioridade padrão |
| Low | `zinc` | `arrow-down` | Pode esperar |

### Sidebar

- Organizada em categorias: **Planejamento**, **Acompanhamento**, **Análise**
- Dashboard fica separado no topo (sem heading)
- Badge de semana exibe "Semana X" (ex: "Semana 8")

## Status Time Tracking (Epic 11)

Rastreamento automático de quanto tempo cada task passa em cada status (Inbox → Backlog → Todo → Doing → Done). Inspirado no Linear — sem ação do usuário.

- **Observer**: `TaskObserver` registrado via `#[ObservedBy]` no Task model
  - `created`: registra status inicial da task
  - `updating`: detecta mudança de `status` e registra novo `TaskStatusChange`
  - Captura mudanças de qualquer origem (Kanban, Task Modal, Command Palette, Daily Planner, MCP)
- **Model**: `TaskStatusChange` — `task_id`, `status` (cast TaskStatus), `changed_at`, `from_status`, `to_status`
- **Accessors no Task**:
  - `time_in_status`: array associativo `[status => minutos]` — tempo acumulado em cada status
  - `current_status_duration`: minutos no status atual
- **Task Modal**: barra horizontal segmentada mostrando tempo proporcional em cada status com cores e tooltips
- **Dashboard**: métricas de tempo médio por status (últimos 30 dias, tasks concluídas)
- **MCP GetTaskTool**: retorna `status_timeline`, `time_in_status`, `current_status_duration_minutes`
- **Testes**: 18 testes Feature (8 backend + 10 frontend/MCP)

## Weekly Review (Epic 12)

Revisão semanal automática com métricas computadas, reflexão pessoal e histórico navegável. Página acessível via sidebar "Review".

- **Model**: `WeeklyReview` — `week_start`, `week_end`, `notes`, `reflection`, `generated_at`
  - Scope `forWeek(CarbonInterface $date)`: busca review da semana contendo a data
  - `getOrCreateForWeek(CarbonInterface $date)`: get-or-create por semana
  - 6 computed methods: `completedTasks()`, `totalHours()`, `hoursByProject()`, `staleTasks()`, `statusTimeAverages()`, `tasksCreatedVsCompleted()`
- **Artisan Command**: `soloboard:weekly-review` — gera/recupera review da semana atual ou especificada
  - `--week=YYYY-MM-DD`: data opcional para gerar review de semana específica
- **Página SFC**: `resources/views/pages/⚡weekly-review.blade.php`
  - Navegação entre semanas (anterior/próxima) com URL sync via `#[Url]`
  - Cards de resumo: tasks completadas, horas totais, projetos trabalhados, criadas vs completadas
  - Horas por projeto com barras de progresso coloridas
  - Lista de tasks completadas com projeto, prioridade e tempo
  - Atenção necessária: tasks ativas sem mudança de status na semana (stale tasks)
  - Reflexão: textarea com auto-save (`wire:model.blur`)
  - Histórico: últimas 4 semanas anteriores, clicáveis para navegação
- **Sidebar**: item "Review" com ícone `clipboard-document-check`

## Task-as-Session (Epic 15)

Modela tasks como sessões de AI coding: 1 task = 1 sessão = 1 PR. Primeira ferramenta PM a modelar o workflow agentic de devs usando Claude Code/Cursor/Copilot.

### Template de User Story para `session_prompt`

O campo `session_prompt` deve seguir a estrutura padronizada de User Story:

```markdown
## User Story
Como [persona], quero [ação] para [benefício].

## Contexto
[Descrição adicional do problema ou situação atual]

## Critérios de Aceitação
- [ ] [Critério 1]
- [ ] [Critério 2]
- [ ] [Critério 3]

## Notas Técnicas
[Arquivos relevantes, dependências, considerações de implementação]
```

**Exemplo:**
```markdown
## User Story
Como desenvolvedor, quero filtrar tasks por data no Kanban para visualizar apenas tasks de um período específico.

## Contexto
Atualmente o Kanban mostra todas as tasks sem opção de filtro temporal.

## Critérios de Aceitação
- [ ] Date picker na toolbar do Kanban
- [ ] Filtrar tasks por due_date
- [ ] Opção "Todas" para remover filtro
- [ ] Persistir filtro na URL via query string

## Notas Técnicas
- Usar <flux:date-picker>
- Página: resources/views/pages/⚡kanban.blade.php
```

- **Campos na Task**: `session_prompt` (text) e `session_result` (text) — o prompt dado ao AI e o resumo do que foi implementado
- **Model Task**:
  - `isSessionTask(): bool` — identifica tasks com `session_prompt` preenchido
  - `sessionSummary(): array` — retorna `{prompt, result, pr_url, commits, files_changed, total_time_minutes, focus_time_minutes}`
- **Factory**: state `->session()` preenche `session_prompt` com texto realista
- **Seeder**: 3 session tasks realistas (doing/done) com prompts e resultados
- **MCP Tools**:
  - `create-task` — aceita `session_prompt` opcional
  - `update-task` — aceita `session_prompt` e `session_result` opcionais
  - `get-task` — retorna `session_summary` com dados completos da sessão
- **MCP Prompt**: `session-planning` — lê o prompt de uma task e gera contexto para planejar a sessão de coding (argumentos: `task_id`)
- **Task Modal**: seção "Sessão de Desenvolvimento" com:
  - Prompt editável (read-only se task done) via `<flux:editor>`
  - Resultado da sessão editável
  - Timeline visual: Prompt → Timer → Commits → PR → Done (cada etapa com status completo/pendente)
- **Quick-Add**: prefixo `>` no título cria session task automaticamente + checkbox "Sessão de Dev" para textarea extra
- **Kanban**: badge 🤖 Sessão (violet) identifica session tasks nos cards

## AI Assistant (Epic 16)

Coach de produtividade com IA integrada via API Anthropic (Claude). Feature totalmente opcional — o SoloBoard funciona perfeitamente sem AI (feature flag off).

### Configuração

```env
SOLOBOARD_AI_ENABLED=true          # false por padrão — tudo funciona sem AI
ANTHROPIC_API_KEY=sk-ant-...       # chave da API Anthropic
SOLOBOARD_AI_MODEL=claude-sonnet-4-20250514  # modelo utilizado
```

- Config em `config/soloboard.php`: `ai_enabled`, `ai_api_key`, `ai_model`, `ai_insights_cache_hours`
- Quando `ai_enabled=false`: botões AI não aparecem, métodos retornam `[]`, sem chamadas à API

### Serviço: `AiAssistantService`

- **Arquivo**: `app/Services/AiAssistantService.php`
- **API**: Anthropic Messages API (`v1/messages`) com timeout de 30s
- **System prompt**: "You are a productivity coach for a solo developer..."
- **Métodos**:
  - `isEnabled(): bool` — verifica feature flag + API key configurada
  - `suggestDailyPlan(Collection $tasks, array $history): array` — sugere tasks para o plano do dia com razão e score (1-100)
  - `analyzeBacklog(Collection $tasks): array` — analisa inbox/backlog e sugere ações (arquivar, priorizar, estimar)
  - `detectPatterns(array $weeklyData): array` — detecta padrões de produtividade (projetos abandonados, over-commitment, falta de deep work)
- **Graceful degradation**: todos os métodos retornam `[]` se desabilitado, sem tasks, ou em caso de erro da API

### AI no Daily Planner

- Botão "✨ Sugerir plano" (só aparece se `ai_enabled=true`)
- Chama `suggestDailyPlan()` via Livewire com loading state
- Modal com lista de sugestões: task + razão + score de prioridade
- Ações: "Adicionar ao plano" individual ou "Adicionar todas"
- Rate limiting: 1 chamada por minuto

### AI no Inbox

- Botão "✨ Analisar backlog" (só aparece se `ai_enabled=true`)
- Chama `analyzeBacklog()` via Livewire com loading state
- Modal com sugestões por task:
  - Priorizar: "Sugerir prioridade: high (motivo: vencendo em 2 dias)"
  - Arquivar: "Sugerir arquivar (motivo: criada há 30 dias, sem atividade)"
  - Estimar: "Sugerir estimativa de tempo"
- Ações: "Aplicar" ou "Ignorar" por sugestão
- Rate limiting: 1 chamada por minuto

### AI Insights no Dashboard

- Seção proativa de insights (só se `ai_enabled=true`)
- Chama `detectPatterns()` com dados semanais do usuário
- Tipos de insight detectados:
  - `abandoned_project` — projeto sem atividade recente
  - `over_commitment` — muitas tasks em progresso simultâneo
  - `productive_hours` / `positive_trend` — padrões positivos
  - `blocker` — bloqueios recorrentes
- Severidade: `info`, `warning`, `critical`
- Ações por insight: navegar para contexto ("Ver projeto", "Abrir inbox") ou "Ignorar" (esconde por 7 dias)
- Cache de 24h (`ai_insights_cache_hours`) para evitar chamadas excessivas à API

### Testes

- `tests/Feature/AiAssistantTest.php` — testes do serviço (mock da API Anthropic)
- `tests/Feature/AiIntegrationTest.php` — testes de integração no Daily Planner e Inbox
- `tests/Feature/AiInsightsTest.php` — testes dos insights no Dashboard

## Recurring Tasks & Templates (Epic 17)

Tasks recorrentes e templates para automatizar atividades repetitivas e manter consistência nos workflows.

### Model RecurringTask

- **Campos**: `title`, `description`, `frequency` (enum), `day_of_week`, `day_of_month`, `priority`, `next_run`, `last_run`, `is_active`, `estimated_minutes`, `project_id`
- **Enum RecurrenceFrequency**: `daily`, `weekdays`, `weekly`, `biweekly`, `monthly`
- **Relacionamentos**: `belongsTo Project`, `hasMany Task`
- **Métodos**:
  - `isDue(): bool` — verifica se está pendente (next_run <= hoje e is_active)
  - `createTask(): Task` — cria task a partir da recurring task
  - `calculateNextRun(): Carbon` — calcula próxima execução baseada na frequência
  - `process(): Task` — cria task e atualiza next_run/last_run

### Model TaskTemplate

- **Campos**: `name`, `slug` (auto-gerado), `description`, `default_priority`, `default_estimated_minutes`, `icon`, `color`, `is_system`
- **Relacionamentos**: `hasMany Task`
- **Métodos**:
  - `createTask(array $overrides = []): Task` — cria task a partir do template
- **Scopes**: `system()`, `custom()`
- **Templates de Sistema**: Code Review, Deploy Checklist, Bug Investigation, Daily Standup, Sprint Planning, Feature Research

### Artisan Command

- `soloboard:process-recurring` — processa recurring tasks pendentes
  - `--dry-run` — simula sem criar tasks
  - Roda via scheduler diariamente às 06:00

### Página Templates

- **Rota**: `/templates` (`routes/web.php`)
- **Componente**: `resources/views/pages/⚡templates.blade.php`
- **Abas**:
  - **Templates**: CRUD de templates, usar template para criar task
  - **Recorrentes**: CRUD de recurring tasks, toggle ativo/pausado, executar agora

### Task Relationships

- `Task.recurring_task_id` — FK para recurring task de origem (nullable)
- `Task.task_template_id` — FK para template de origem (nullable)
- Métodos: `isFromRecurring(): bool`, `isFromTemplate(): bool`

### MCP Tools

- `list-templates` — lista templates disponíveis
- `apply-template` — cria task a partir de template
- `list-recurring-tasks` — lista recurring tasks
- `create-recurring-task` — cria nova recurring task
- `toggle-recurring-task` — ativa/pausa recurring task

### Testes

- `tests/Feature/RecurringTaskTest.php` — model, command, cálculos de next_run
- `tests/Feature/TaskTemplateTest.php` — model, factory, seeder
- `tests/Feature/TemplatesPageTest.php` — UI, CRUD, integração

## Keyboard Shortcuts

| Atalho   | Ação                        |
| -------- | --------------------------- |
| `Ctrl+N` | Nova task (quick-add modal) |
| `Ctrl+B` | Ir para Kanban (Board)      |
| `Ctrl+D` | Ir para Daily Planner       |
| `Ctrl+I` | Ir para Inbox               |
| `Ctrl+T` | Start/stop timer            |
| `Esc`    | Fechar modal                |
| `Ctrl+K` | Command Palette             |

Todos os atalhos requerem `Ctrl` (ou `Cmd` no Mac). Ignorar quando foco em `input`/`textarea`/`select`/`[contenteditable]`.

## Regras Gerais

- Commits: conventional commits (`feat:`, `fix:`, `chore:`)
- Testes Feature (Pest) para lógica de negócio
- Keyboard-first: todo componente interativo deve ter suporte a atalho
- Empty states com ícone + texto + CTA + dica de atalho
- Deletar sempre com confirmação via `<flux:modal>`

## MCP Server

O SoloBoard expõe um MCP Server para integração com AI clients (Claude Code, Cursor, etc.).

- **Configurar**: `claude mcp add --transport http soloboard https://regnt.sophostech.com.br/mcp`
- **Header**: `Authorization: Bearer {SOLOBOARD_MCP_KEY}` (definido no `.env`)
- **Tools disponíveis**:
  - `list-tasks` — Lista tasks com filtros (project_slug, status, limit)
  - `get-task` — Detalhes completos de uma task (inclui status_timeline, time_in_status, current_status_duration_minutes)
  - `create-task` — Cria nova task (default: inbox/medium)
  - `update-task` — Atualiza task (markAsDone ao mudar para done)
  - `delete-task` — Deleta task e time entries
  - `list-features` — Lista features com filtros (project_slug, status, limit)
  - `get-feature` — Detalhes completos de uma feature (spec, tasks, time entries, progress)
  - `create-feature` — Cria nova feature com spec e prioridade
  - `update-feature` — Atualiza feature (spec, prioridade, due_date, projeto)
  - `delete-feature` — Deleta feature (desvincula tasks, deleta time entries)
  - `add-task-to-feature` — Vincula task existente a uma feature (herda projeto se não tiver)
  - `start-timer` — Inicia timer para task ou feature (para outros automaticamente)
  - `stop-timer` — Para timer com notas opcionais
  - `timer-status` — Mostra timer ativo (task ou feature)
  - `today-plan` — Plano do dia (auto-cria)
  - `suggest-tasks` — Sugere tasks prioritárias
  - `add-to-plan` — Adiciona task ao plano do dia
  - `list-projects` — Lista projetos por status
- **Resource**: `soloboard://overview` — Resumo geral do estado (inclui active_features)
- **Prompts**:
  - `daily-planning` — Ajuda a planejar o dia
  - `session-planning` — Lê prompt de uma task e gera contexto para planejar sessão de AI coding
  - `feature-planning` — Lê spec de uma feature e gera contexto para planejar implementação

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/mcp (MCP) - v0
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/flux-pro (FLUXUI_PRO) - v2
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues (`rfl-designer/regnt-solo`) via the `gh` CLI; external PRs are not a triage surface. See `docs/agents/issue-tracker.md`.

### Triage labels

Canonical default vocabulary (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context layout — one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
