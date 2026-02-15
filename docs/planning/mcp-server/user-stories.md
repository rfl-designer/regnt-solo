---
epic: "Epic 10: SoloBoard MCP Server"
description: "MCP Server para o SoloBoard — expoe tools de CRUD de tasks, timer (start/stop/status), daily plan, projetos, resource de overview e prompt de planejamento diario via protocolo MCP, autenticado por API key custom."
created_at: "2026-02-15"
total_stories: 5
complexity_summary:
  small: 1
  medium: 3
  large: 1
type_summary:
  core: 5
  frontend: 0
  both: 0
---

# User Stories: Epic 10 -- SoloBoard MCP Server

## Visao Geral

Este epic implementa um MCP Server para o SoloBoard, permitindo que clientes AI (Claude Desktop, Cursor, etc.) interajam com a aplicacao via Model Context Protocol. O server expoe tools para CRUD de tasks, controle de timer (start/stop/status), daily plan, listagem de projetos, um resource com overview do estado atual e um prompt para planejamento diario. A autenticacao e feita via API key custom (sem Sanctum/Passport), validada por middleware dedicado que inspeciona o header `Authorization`.

**Pre-requisitos:** Todos os Models (Task, Project, TimeEntry, DailyPlan), Enums (TaskStatus, TaskPriority, ProjectStatus, ProjectPriority), Factories e Migrations ja existem e estao funcionais. O pacote `laravel/mcp` v0.5.6 ja esta instalado como dependencia. Os scopes `inbox`, `active`, `byStatus`, `overdue`, `unassigned`, `doneThisWeek` ja existem no Model Task. Os metodos `markAsDone()`, `isOverdue()`, `isRunning()` ja existem. `TimeEntry::stopAllRunning()` e `DailyPlan::getOrCreateForDate()` ja existem.

## Ordem de Execucao

1. **US-001** -- Configuracao base: routes/ai.php, SoloBoardServer, middleware McpAuth, config/soloboard.php (core)
2. **US-002** -- MCP Tools: Tasks CRUD + listagem (core)
3. **US-003** -- MCP Tools: Timer start/stop/status (core)
4. **US-004** -- MCP Tools: Daily Plan + Projects (core)
5. **US-005** -- MCP Resource: ProjectOverview + Prompt: DailyPlanning + documentacao CLAUDE.md (core)

---

## Lista de User Stories

### US-001: Configuracao base do MCP Server com autenticacao por API key

**Como** desenvolvedor
**Quero** ter o MCP Server configurado com rota, server class, middleware de autenticacao por API key e config dedicada
**Para** que clientes AI possam se conectar ao SoloBoard de forma segura via protocolo MCP

**Criterios de Aceitacao:**

- [ ] `routes/ai.php` existe e esta publicado (via `php artisan vendor:publish --tag=ai-routes`)
- [ ] `routes/ai.php` registra o server via `Mcp::web('/mcp/soloboard', SoloBoardServer::class)->middleware('mcp.auth')`
- [ ] `bootstrap/app.php` atualizado para incluir `ai: __DIR__.'/../routes/ai.php'` no `withRouting`
- [ ] `app/Mcp/Servers/SoloBoardServer.php` existe e extende `Laravel\Mcp\Server`
- [ ] SoloBoardServer define `$name = 'SoloBoard'`, `$version = '1.0.0'` e `$instructions` descrevendo as capacidades do server
- [ ] SoloBoardServer registra arrays vazios de `$tools`, `$resources` e `$prompts` (serao preenchidos nas proximas US)
- [ ] `app/Http/Middleware/McpAuth.php` existe e valida o header `Authorization: Bearer <key>` contra `config('soloboard.mcp_key')`
- [ ] McpAuth retorna 401 JSON `{"error": "Unauthorized"}` quando key ausente ou invalida
- [ ] McpAuth permite passagem quando key e valida
- [ ] Middleware `mcp.auth` registrado como alias em `bootstrap/app.php` via `$middleware->alias(['mcp.auth' => McpAuth::class])`
- [ ] `config/soloboard.php` existe com chave `'mcp_key' => env('SOLOBOARD_MCP_KEY')`
- [ ] `.env.example` atualizado com `SOLOBOARD_MCP_KEY=` (valor vazio como placeholder)
- [ ] Given request POST para `/mcp/soloboard` sem header Authorization, When processado, Then retorna 401
- [ ] Given request POST para `/mcp/soloboard` com header `Authorization: Bearer <key-valida>`, When processado, Then retorna 200 (resposta MCP valida)
- [ ] Given request POST para `/mcp/soloboard` com header `Authorization: Bearer <key-invalida>`, When processado, Then retorna 401
- [ ] Teste Feature: request sem key retorna 401, com key valida retorna 200, com key invalida retorna 401

**Complexidade:** M
**Estimativa:** 3h
**Dependencias:** Nenhuma
**Tipo:** core

**Arquivos afetados:**
- `routes/ai.php` (criar via publish)
- `app/Mcp/Servers/SoloBoardServer.php` (criar via `php artisan make:mcp-server SoloBoardServer`)
- `app/Http/Middleware/McpAuth.php` (criar)
- `config/soloboard.php` (criar)
- `bootstrap/app.php` (editar -- adicionar rota ai.php e alias do middleware)
- `.env.example` (editar -- adicionar SOLOBOARD_MCP_KEY)
- `tests/Feature/Mcp/McpAuthTest.php` (criar)

**Commit message:** `feat: configure MCP server with API key authentication and routing`

---

### US-002: MCP Tools para CRUD completo de Tasks

**Como** cliente AI
**Quero** listar, buscar, criar, atualizar e deletar tasks via MCP tools
**Para** gerenciar tasks do SoloBoard diretamente a partir de um assistente AI

**Criterios de Aceitacao:**

- [ ] `app/Mcp/Tools/ListTasksTool.php` existe com schema: `status` (string, opcional, enum dos valores de TaskStatus), `project_slug` (string, opcional), `limit` (integer, opcional, default 20)
- [ ] ListTasksTool retorna lista de tasks filtradas com id, title, status (label), priority (label), project name, due_date, estimated_minutes, is_overdue, is_running
- [ ] ListTasksTool usa eager loading de `project` para evitar N+1
- [ ] ListTasksTool anotado com `#[IsReadOnly]` e `#[IsIdempotent]`
- [ ] `app/Mcp/Tools/GetTaskTool.php` existe com schema: `task_id` (integer, required)
- [ ] GetTaskTool retorna task completa com relationships: project (name, slug, emoji), timeEntries (started_at, stopped_at, duration_minutes, notes), dailyPlans (date)
- [ ] GetTaskTool retorna erro quando task nao encontrada
- [ ] GetTaskTool anotado com `#[IsReadOnly]` e `#[IsIdempotent]`
- [ ] `app/Mcp/Tools/CreateTaskTool.php` existe com schema: `title` (string, required), `description` (string, opcional), `project_slug` (string, opcional), `priority` (string, opcional, enum), `due_date` (string, opcional, formato Y-m-d), `estimated_minutes` (integer, opcional)
- [ ] CreateTaskTool cria task com `status=inbox` como padrao
- [ ] CreateTaskTool resolve project_slug para project_id (retorna erro se slug invalido)
- [ ] CreateTaskTool valida campos obrigatorios (title)
- [ ] `app/Mcp/Tools/UpdateTaskTool.php` existe com schema: `task_id` (integer, required), `title` (string, opcional), `description` (string, opcional), `status` (string, opcional, enum), `priority` (string, opcional, enum), `project_slug` (string, opcional), `due_date` (string, opcional), `estimated_minutes` (integer, opcional)
- [ ] UpdateTaskTool chama `markAsDone()` quando status muda para `done`
- [ ] UpdateTaskTool limpa `completed_at` quando status muda de `done` para outro
- [ ] UpdateTaskTool retorna erro quando task nao encontrada
- [ ] `app/Mcp/Tools/DeleteTaskTool.php` existe com schema: `task_id` (integer, required)
- [ ] DeleteTaskTool deleta task (time entries removidas em cascade via FK)
- [ ] DeleteTaskTool retorna erro quando task nao encontrada
- [ ] DeleteTaskTool anotado com `#[IsDestructive]`
- [ ] Todas as 5 tools registradas no array `$tools` do SoloBoardServer
- [ ] Testes: listar tasks com filtros, buscar task por id, criar task com e sem projeto, atualizar task (incluindo mover para done), deletar task, erros de validacao

**Complexidade:** G
**Estimativa:** 5h
**Dependencias:** US-001
**Tipo:** core

**Arquivos afetados:**
- `app/Mcp/Tools/ListTasksTool.php` (criar via `php artisan make:mcp-tool ListTasksTool`)
- `app/Mcp/Tools/GetTaskTool.php` (criar via `php artisan make:mcp-tool GetTaskTool`)
- `app/Mcp/Tools/CreateTaskTool.php` (criar via `php artisan make:mcp-tool CreateTaskTool`)
- `app/Mcp/Tools/UpdateTaskTool.php` (criar via `php artisan make:mcp-tool UpdateTaskTool`)
- `app/Mcp/Tools/DeleteTaskTool.php` (criar via `php artisan make:mcp-tool DeleteTaskTool`)
- `app/Mcp/Servers/SoloBoardServer.php` (editar -- registrar tools)
- `tests/Feature/Mcp/TaskToolsTest.php` (criar)

**Commit message:** `feat: add MCP tools for full task CRUD and listing`

---

### US-003: MCP Tools para controle de Timer (start/stop/status)

**Como** cliente AI
**Quero** iniciar, parar e consultar o status do timer via MCP tools
**Para** controlar o rastreamento de tempo das tasks sem sair do assistente AI

**Criterios de Aceitacao:**

- [ ] `app/Mcp/Tools/StartTimerTool.php` existe com schema: `task_id` (integer, required), `notes` (string, opcional)
- [ ] StartTimerTool para todos os timers rodando antes de iniciar novo (via `TimeEntry::stopAllRunning()`)
- [ ] StartTimerTool cria novo TimeEntry com `started_at = now()`, `stopped_at = null`, task_id e notes
- [ ] StartTimerTool retorna confirmacao com task title e horario de inicio
- [ ] StartTimerTool retorna erro quando task nao encontrada
- [ ] `app/Mcp/Tools/StopTimerTool.php` existe com schema: `notes` (string, opcional)
- [ ] StopTimerTool encontra o timer rodando (TimeEntry running) e atualiza `stopped_at = now()`
- [ ] StopTimerTool atualiza notes se fornecido (append ou replace, conforme parametro)
- [ ] StopTimerTool retorna confirmacao com task title, duracao em minutos e notas
- [ ] StopTimerTool retorna mensagem informativa quando nao ha timer rodando
- [ ] `app/Mcp/Tools/TimerStatusTool.php` existe sem schema (nenhum parametro)
- [ ] TimerStatusTool retorna info do timer ativo: task title, started_at, duracao parcial em minutos, notes
- [ ] TimerStatusTool retorna mensagem "Nenhum timer ativo" quando nao ha timer rodando
- [ ] TimerStatusTool anotado com `#[IsReadOnly]` e `#[IsIdempotent]`
- [ ] Todas as 3 tools registradas no array `$tools` do SoloBoardServer
- [ ] Testes: start timer (para anteriores e cria novo), status mostra timer ativo, stop com notas registra duracao, status sem timer ativo, start com task inexistente

**Complexidade:** M
**Estimativa:** 3h
**Dependencias:** US-001
**Tipo:** core

**Arquivos afetados:**
- `app/Mcp/Tools/StartTimerTool.php` (criar via `php artisan make:mcp-tool StartTimerTool`)
- `app/Mcp/Tools/StopTimerTool.php` (criar via `php artisan make:mcp-tool StopTimerTool`)
- `app/Mcp/Tools/TimerStatusTool.php` (criar via `php artisan make:mcp-tool TimerStatusTool`)
- `app/Mcp/Servers/SoloBoardServer.php` (editar -- registrar tools)
- `tests/Feature/Mcp/TimerToolsTest.php` (criar)

**Commit message:** `feat: add MCP tools for timer start, stop, and status`

---

### US-004: MCP Tools para Daily Plan e Projects

**Como** cliente AI
**Quero** consultar o plano do dia, sugerir tasks prioritarias, adicionar tasks ao plano e listar projetos via MCP tools
**Para** ajudar no planejamento diario e na organizacao de projetos a partir do assistente AI

**Criterios de Aceitacao:**

- [ ] `app/Mcp/Tools/TodayPlanTool.php` existe sem schema (nenhum parametro)
- [ ] TodayPlanTool retorna o plano de hoje usando `DailyPlan::getOrCreateForDate(today())` (cria se nao existir)
- [ ] TodayPlanTool retorna: date, notes, lista de tasks do plano (title, status label, priority label, completed_at do pivot), completion_rate
- [ ] TodayPlanTool usa eager loading de `tasks.project` para evitar N+1
- [ ] TodayPlanTool anotado com `#[IsReadOnly]` e `#[IsIdempotent]`
- [ ] `app/Mcp/Tools/SuggestTasksTool.php` existe com schema: `limit` (integer, opcional, default 5)
- [ ] SuggestTasksTool retorna tasks prioritarias na ordem: overdue primeiro (ordenadas por due_date asc), depois doing (ordenadas por updated_at desc), depois todo (ordenadas por priority desc, created_at asc)
- [ ] SuggestTasksTool exclui tasks com status `inbox` e `done`
- [ ] SuggestTasksTool retorna id, title, status label, priority label, due_date, project name, is_overdue
- [ ] SuggestTasksTool anotado com `#[IsReadOnly]` e `#[IsIdempotent]`
- [ ] `app/Mcp/Tools/AddToPlanTool.php` existe com schema: `task_id` (integer, required)
- [ ] AddToPlanTool adiciona task ao plano de hoje (via `DailyPlan::getOrCreateForDate(today())`)
- [ ] AddToPlanTool define `sort_order` como proximo valor sequencial no plano
- [ ] AddToPlanTool retorna erro se task ja esta no plano de hoje
- [ ] AddToPlanTool retorna erro se task nao encontrada
- [ ] AddToPlanTool retorna confirmacao com task title e total de tasks no plano
- [ ] `app/Mcp/Tools/ListProjectsTool.php` existe com schema: `status` (string, opcional, enum dos valores de ProjectStatus)
- [ ] ListProjectsTool retorna projetos filtrados por status (default: todos) com id, name, slug, emoji, color, status label, priority label, task_count (total de tasks ativas)
- [ ] ListProjectsTool usa scope `ordered()` para ordenacao por prioridade
- [ ] ListProjectsTool anotado com `#[IsReadOnly]` e `#[IsIdempotent]`
- [ ] Todas as 4 tools registradas no array `$tools` do SoloBoardServer
- [ ] Testes: plano de hoje (cria se nao existe), sugestao de tasks (ordem correta), adicionar task ao plano (sucesso e duplicata), listar projetos com e sem filtro de status

**Complexidade:** M
**Estimativa:** 4h
**Dependencias:** US-001
**Tipo:** core

**Arquivos afetados:**
- `app/Mcp/Tools/TodayPlanTool.php` (criar via `php artisan make:mcp-tool TodayPlanTool`)
- `app/Mcp/Tools/SuggestTasksTool.php` (criar via `php artisan make:mcp-tool SuggestTasksTool`)
- `app/Mcp/Tools/AddToPlanTool.php` (criar via `php artisan make:mcp-tool AddToPlanTool`)
- `app/Mcp/Tools/ListProjectsTool.php` (criar via `php artisan make:mcp-tool ListProjectsTool`)
- `app/Mcp/Servers/SoloBoardServer.php` (editar -- registrar tools)
- `tests/Feature/Mcp/DailyPlanToolsTest.php` (criar)
- `tests/Feature/Mcp/ProjectToolsTest.php` (criar)

**Commit message:** `feat: add MCP tools for daily plan management and project listing`

---

### US-005: MCP Resource ProjectOverview, Prompt DailyPlanning e documentacao CLAUDE.md

**Como** cliente AI
**Quero** acessar um resource com overview do estado atual do SoloBoard e um prompt para planejamento diario
**Para** ter contexto completo da aplicacao e receber orientacao estruturada para planejar o dia

**Criterios de Aceitacao:**

- [ ] `app/Mcp/Resources/ProjectOverviewResource.php` existe com `$uri = 'soloboard://resources/project-overview'` e `$mimeType = 'text/plain'`
- [ ] ProjectOverviewResource retorna resumo textual com: total de tasks por status, tasks overdue, timer ativo (se houver), plano de hoje (completion rate, tasks pendentes), projetos ativos com contagem de tasks
- [ ] ProjectOverviewResource usa queries eficientes (aggregates, nao carrega todos os registros)
- [ ] `app/Mcp/Prompts/DailyPlanningPrompt.php` existe sem arguments
- [ ] DailyPlanningPrompt retorna array de Response com mensagem de sistema instruindo o AI a ajudar no planejamento diario e mensagem de usuario com contexto atual (tasks overdue, tasks doing, sugestoes de prioridade)
- [ ] DailyPlanningPrompt usa dados reais do banco (tasks overdue, doing, plano de hoje)
- [ ] Resource e Prompt registrados nos arrays `$resources` e `$prompts` do SoloBoardServer
- [ ] `CLAUDE.md` atualizado (ou criado) com secao "MCP Server" contendo: URL do endpoint, como configurar a API key, lista de tools disponiveis com descricao breve, exemplo de configuracao para Claude Desktop
- [ ] Teste: resource retorna overview com dados corretos (contagens por status, timer ativo, plano de hoje)
- [ ] Teste: prompt retorna mensagens de sistema e usuario com contexto real

**Complexidade:** P
**Estimativa:** 2h
**Dependencias:** US-002, US-003, US-004
**Tipo:** core

**Arquivos afetados:**
- `app/Mcp/Resources/ProjectOverviewResource.php` (criar via `php artisan make:mcp-resource ProjectOverviewResource`)
- `app/Mcp/Prompts/DailyPlanningPrompt.php` (criar via `php artisan make:mcp-prompt DailyPlanningPrompt`)
- `app/Mcp/Servers/SoloBoardServer.php` (editar -- registrar resource e prompt)
- `CLAUDE.md` (editar ou criar -- adicionar secao MCP Server)
- `tests/Feature/Mcp/ResourceAndPromptTest.php` (criar)

**Commit message:** `feat: add MCP resource, prompt, and CLAUDE.md documentation`

---

## Resumo de Complexidade

| Complexidade | Quantidade | User Stories |
|-------------|-----------|--------------|
| P (Pequeno) | 1 | US-005 |
| M (Medio)   | 3 | US-001, US-003, US-004 |
| G (Grande)  | 1 | US-002 |

**Estimativa total:** ~17h

## Grafo de Dependencias

```
US-001 (Config base: server + auth + rota)
  |
  +---> US-002 (Tools: Tasks CRUD) --------+
  |                                         |
  +---> US-003 (Tools: Timer) -------------+---> US-005 (Resource + Prompt + CLAUDE.md)
  |                                         |
  +---> US-004 (Tools: Daily Plan + Projects)+
```

## Notas Tecnicas

1. **Autenticacao custom**: O SoloBoard e single-user e nao usa Sanctum/Passport. A autenticacao MCP e feita via middleware custom `McpAuth` que valida `Authorization: Bearer <key>` contra `config('soloboard.mcp_key')`. Isso segue a documentacao do Laravel MCP para "Custom MCP Authentication".

2. **Padrao de Tools**: Cada tool e uma classe em `app/Mcp/Tools/` que extende `Laravel\Mcp\Server\Tool`. Define `$description`, metodo `schema(JsonSchema $schema)` para input e `handle(Request $request)` para logica. Respostas via `Response::text()` para texto e `Response::error()` para erros.

3. **Testes MCP**: Usar o padrao de testes unitarios do Laravel MCP: `SoloBoardServer::tool(ToolClass::class, [...args])` com assertions `->assertOk()`, `->assertSee()`, `->assertHasErrors()`. Nao precisa de HTTP requests reais.

4. **Annotations**: Tools read-only usam `#[IsReadOnly]` e `#[IsIdempotent]`. Tools destrutivas usam `#[IsDestructive]`. Isso ajuda clientes AI a entender o comportamento de cada tool.

5. **Artisan make commands**: Usar `php artisan make:mcp-server`, `php artisan make:mcp-tool`, `php artisan make:mcp-resource`, `php artisan make:mcp-prompt` para criar os arquivos base. Depois customizar.

6. **Locale PT-BR**: Respostas das tools em portugues (labels de enums, mensagens de confirmacao/erro). Codigo (variaveis, metodos, classes) em ingles.

7. **Eager loading**: ListTasksTool e TodayPlanTool devem usar eager loading para evitar N+1. GetTaskTool carrega todas as relationships relevantes.

8. **SuggestTasksTool ordenacao**: A logica de sugestao prioriza: (1) overdue por due_date asc, (2) doing por updated_at desc, (3) todo por prioridade desc + created_at asc. Usa `UNION` ou queries separadas com merge.

9. **Config via env**: A API key e configurada via `SOLOBOARD_MCP_KEY` no `.env` e acessada via `config('soloboard.mcp_key')`. Nunca usar `env()` diretamente fora de config files.

10. **CLAUDE.md**: O arquivo de documentacao deve conter instrucoes claras para configurar o MCP client (Claude Desktop, Cursor) com a URL do endpoint e a API key. Incluir exemplo de `claude_desktop_config.json`.
