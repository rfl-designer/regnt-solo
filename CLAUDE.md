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

- **Configurar**: `claude mcp add --transport http soloboard http://regnt.test/mcp`
- **Header**: `Authorization: Bearer {SOLOBOARD_MCP_KEY}` (definido no `.env`)
- **Tools disponíveis**:
  - `list-tasks` — Lista tasks com filtros (project_slug, status, limit)
  - `get-task` — Detalhes completos de uma task (inclui status_timeline, time_in_status, current_status_duration_minutes)
  - `create-task` — Cria nova task (default: inbox/medium)
  - `update-task` — Atualiza task (markAsDone ao mudar para done)
  - `delete-task` — Deleta task e time entries
  - `start-timer` — Inicia timer (para outros automaticamente)
  - `stop-timer` — Para timer com notas opcionais
  - `timer-status` — Mostra timer ativo
  - `today-plan` — Plano do dia (auto-cria)
  - `suggest-tasks` — Sugere tasks prioritárias
  - `add-to-plan` — Adiciona task ao plano do dia
  - `list-projects` — Lista projetos por status
- **Resource**: `soloboard://overview` — Resumo geral do estado
- **Prompts**:
  - `daily-planning` — Ajuda a planejar o dia
  - `session-planning` — Lê prompt de uma task e gera contexto para planejar sessão de AI coding

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.17
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

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `mcp-development` — Develops MCP servers, tools, resources, and prompts. Activates when creating MCP tools, resources, or prompts; setting up AI integrations; debugging MCP connections; working with routes/ai.php; or when the user mentions MCP, Model Context Protocol, AI tools, AI server, or building tools for AI assistants.
- `fluxui-development` — Develops UIs with Flux UI Pro components. Activates when creating buttons, forms, modals, inputs, tables, charts, date pickers, or UI components; replacing HTML elements with Flux; working with flux: components; or when the user mentions Flux, component library, UI components, form fields, or asks about available Flux components.
- `livewire-development` — Develops reactive Livewire 4 components. Activates when creating, updating, or modifying Livewire components; working with wire:model, wire:click, wire:loading, or any wire: directives; adding real-time updates, loading states, or reactivity; debugging component behavior; writing Livewire tests; or when the user mentions Livewire, component, counter, or reactive UI.
- `pest-testing` — Tests applications using the Pest 4 PHP framework. Activates when writing tests, creating unit or feature tests, adding assertions, testing Livewire components, browser testing, debugging test failures, working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion, coverage, or needs to verify functionality works. ALSO activates when: running 'php artisan test', creating test files, fixing failing tests, adding test coverage, implementing red-green-refactor, writing it() or test() blocks, using Livewire::test(), or when any implementation needs verification through automated tests.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.
- `developing-with-fortify` — Laravel Fortify headless authentication backend development. Activate when implementing authentication features including login, registration, password reset, email verification, two-factor authentication (2FA/TOTP), profile updates, headless auth, authentication scaffolding, or auth guards in Laravel applications.

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

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd and will be available at: `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs for the user.
- You must not run any commands to make the site available via HTTP(S). It is always available through Laravel Herd.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

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
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== mcp/core rules ===

# Laravel MCP

- Laravel MCP allows you to rapidly build MCP servers for your Laravel applications.
- IMPORTANT: laravel/mcp is very new. Always use the `search-docs` tool for authoritative documentation on writing and testing Laravel MCP servers, tools, resources, and prompts.
- IMPORTANT: Activate `mcp-development` every time you're working with an MCP-related task.

=== fluxui-pro/core rules ===

# Flux UI Pro

- Flux UI is the official Livewire component library. This project uses the Pro edition, which includes all free and Pro components and variants.
- Use `<flux:*>` components when available; they are the recommended way to build Livewire interfaces.
- IMPORTANT: Activate `fluxui-development` when working with Flux UI components.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces using only PHP — no JavaScript required.
- Instead of writing frontend code in JavaScript frameworks, you use Alpine.js to build the UI when client-side interactions are required.
- State lives on the server; the UI reflects it. Validate and authorize in actions (they're like HTTP requests).
- IMPORTANT: Activate `livewire-development` every time you're working with Livewire-related tasks.

=== boost/core rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.
- CRITICAL: ALWAYS use `search-docs` tool for version-specific Pest documentation and updated code examples.
- IMPORTANT: Activate `pest-testing` every time you're working with a Pest or testing-related task.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.

=== laravel/fortify rules ===

# Laravel Fortify

- Fortify is a headless authentication backend that provides authentication routes and controllers for Laravel applications.
- IMPORTANT: Always use the `search-docs` tool for detailed Laravel Fortify patterns and documentation.
- IMPORTANT: Activate `developing-with-fortify` skill when working with Fortify authentication features.

</laravel-boost-guidelines>
