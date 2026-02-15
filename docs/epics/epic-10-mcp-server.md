# EPIC 10: SoloBoard MCP Server

> **Sessão Claude Code:** Feature fundacional pós-MVP. Transforma o SoloBoard de ferramenta passiva em infraestrutura invisível do workflow de desenvolvimento.
> **Prioridade:** #1 (habilitador de todas as features futuras)
> **Estimativa:** ~2h · 5 tasks
> **Dependência:** MVP completo (Epics 0-9)

---

## Contexto

O MCP (Model Context Protocol) permite que o Claude Code, Cursor, Windsurf e outros clientes AI interajam diretamente com o SoloBoard durante sessões de desenvolvimento. Com o pacote oficial `laravel/mcp`, a implementação segue padrões nativos do Laravel.

**Workflow alvo:**

```
Dev: "Vamos trabalhar na task #42 do SoloBoard"
Claude Code:
  1. get_task(42)           → lê título, prompt, contexto
  2. start_timer(42)        → timer começa automaticamente
  3. update_task(42, status: doing)
  4. [... sessão de desenvolvimento ...]
  5. stop_timer(42, notes: "Implementado endpoint de autenticação")
  6. update_task(42, status: done)
```

---

## Task 10.1 — Instalar e configurar Laravel MCP

```yaml
Prompt: |
    Instale e configure o pacote laravel/mcp:

    1. composer require laravel/mcp
    2. php artisan vendor:publish --tag=ai-routes
    3. Crie o server: php artisan make:mcp-server SoloBoardServer

    Configure o SoloBoardServer em app/Mcp/Servers/SoloBoardServer.php:
    - name: 'SoloBoard'
    - version: '1.0.0'
    - instructions: prompt descritivo para o LLM sobre o que o SoloBoard faz
      e como usar as tools (em inglês, pois é para o AI)

    Registre o server como web em routes/ai.php:
    - Mcp::web('/mcp', SoloBoardServer::class)
    - Middleware: autenticação via API key simples

    Crie middleware app/Http/Middleware/McpAuth.php:
    - Valida header 'Authorization: Bearer {key}'
    - Key definida em .env: SOLOBOARD_MCP_KEY=soloboard-mcp-secret
    - Registre em config/soloboard.php

    Adicione ao .env e .env.example:
    - SOLOBOARD_MCP_KEY=soloboard-mcp-secret

Acceptance Criteria:
    - laravel/mcp instalado e configurado
    - SoloBoardServer criado e registrado como web server
    - Middleware de API key funciona (rejeita sem key, aceita com key)
    - php artisan mcp:inspector mcp funciona para testar
    - Teste Feature: request sem key retorna 401, com key retorna 200

Arquivos:
    - composer.json
    - routes/ai.php
    - app/Mcp/Servers/SoloBoardServer.php
    - app/Http/Middleware/McpAuth.php
    - config/soloboard.php
    - .env, .env.example
    - tests/Feature/McpAuthTest.php

Commit: "feat: install Laravel MCP and configure SoloBoard server with API key auth"
```

---

## Task 10.2 — MCP Tools: Tasks (CRUD + listagem)

```yaml
Prompt: |
    Crie as MCP tools para gerenciar tasks. Usar php artisan make:mcp-tool para cada:

    1. ListTasksTool (list-tasks):
       - Input schema: project_slug (string, optional), status (string, optional),
         limit (integer, default 20)
       - Retorna lista de tasks filtradas (id, title, status, priority, project, due_date,
         estimated_minutes, is_overdue, is_running)
       - Annotation: #[IsReadOnly]

    2. GetTaskTool (get-task):
       - Input schema: task_id (integer, required)
       - Retorna task completa com project, time_entries, daily_plans
       - Annotation: #[IsReadOnly]

    3. CreateTaskTool (create-task):
       - Input schema: title (required), project_slug (optional), priority (optional),
         due_date (optional), estimated_minutes (optional), description (optional)
       - Cria task com status=inbox (padrão) ou status informado
       - Retorna task criada

    4. UpdateTaskTool (update-task):
       - Input schema: task_id (required), title, status, priority, project_slug,
         due_date, estimated_minutes, description (todos opcionais)
       - Ao mudar status para done: chama markAsDone() (para timer, preenche completed_at)
       - Retorna task atualizada

    5. DeleteTaskTool (delete-task):
       - Input schema: task_id (required)
       - Deleta task e suas TimeEntries (cascade)
       - Annotation: #[IsDestructive]

    Registre todas as tools no SoloBoardServer.

    Todas as tools devem:
    - Usar Request validation com mensagens claras para o AI
    - Retornar Response::text() com JSON formatado
    - Ter descriptions claras em inglês para o LLM

Acceptance Criteria:
    - Todas 5 tools registradas e funcionais
    - list-tasks filtra por projeto e status
    - create-task cria no inbox por padrão
    - update-task chama markAsDone() ao mover para done
    - delete-task com cascade
    - Teste Feature (via unit test do MCP): CRUD completo

Arquivos:
    - app/Mcp/Tools/ListTasksTool.php
    - app/Mcp/Tools/GetTaskTool.php
    - app/Mcp/Tools/CreateTaskTool.php
    - app/Mcp/Tools/UpdateTaskTool.php
    - app/Mcp/Tools/DeleteTaskTool.php
    - app/Mcp/Servers/SoloBoardServer.php (atualizar)
    - tests/Feature/Mcp/TaskToolsTest.php

Commit: "feat: MCP tools for task CRUD and listing"
```

---

## Task 10.3 — MCP Tools: Timer (start/stop)

```yaml
Prompt: |
    Crie as MCP tools para controlar o timer:

    1. StartTimerTool (start-timer):
       - Input schema: task_id (integer, required)
       - Chama TimeEntry::stopAllRunning() antes de iniciar
       - Cria nova TimeEntry com started_at=now
       - Retorna: task info + timer info (entry_id, started_at)

    2. StopTimerTool (stop-timer):
       - Input schema: task_id (integer, required), notes (string, optional)
       - Encontra TimeEntry running da task
       - Preenche stopped_at=now e notes
       - Retorna: task info + duration_minutes + notes

    3. TimerStatusTool (timer-status):
       - Sem input obrigatório
       - Retorna: timer ativo (se existir) com task_id, task_title,
         started_at, duration_minutes
       - Se nenhum timer: retorna mensagem "Nenhum timer ativo"
       - Annotation: #[IsReadOnly]

    Registre no SoloBoardServer.

Acceptance Criteria:
    - start-timer para todos os timers antes de iniciar novo
    - stop-timer registra notas e calcula duração
    - timer-status mostra timer ativo ou mensagem
    - Teste Feature: start, status, stop com notas, verificar DB

Arquivos:
    - app/Mcp/Tools/StartTimerTool.php
    - app/Mcp/Tools/StopTimerTool.php
    - app/Mcp/Tools/TimerStatusTool.php
    - app/Mcp/Servers/SoloBoardServer.php (atualizar)
    - tests/Feature/Mcp/TimerToolsTest.php

Commit: "feat: MCP tools for timer start, stop, and status"
```

---

## Task 10.4 — MCP Tools: Daily Plan + Projects

```yaml
Prompt: |
    Crie MCP tools para Daily Plan e projetos:

    1. TodayPlanTool (today-plan):
       - Sem input obrigatório
       - Retorna: plano de hoje (cria se não existir via getOrCreateForDate)
         com tasks do plano (título, status, completed_at), completion_rate, notes
       - Annotation: #[IsReadOnly]

    2. SuggestTasksTool (suggest-tasks):
       - Sem input obrigatório
       - Retorna tasks prioritárias que não estão no plano de hoje:
         - Tasks overdue primeiro
         - Tasks doing
         - Tasks todo ordenadas por prioridade
         - Limite: 10 sugestões
       - Annotation: #[IsReadOnly]

    3. AddToPlanTool (add-to-plan):
       - Input schema: task_id (integer, required)
       - Adiciona task ao plano de hoje (cria plano se necessário)
       - Retorna plano atualizado

    4. ListProjectsTool (list-projects):
       - Input schema: status (string, optional, default 'active')
       - Retorna projetos com: id, name, slug, color, emoji, status, priority,
         active_tasks_count
       - Annotation: #[IsReadOnly]

    Registre no SoloBoardServer.

Acceptance Criteria:
    - today-plan cria plano automaticamente
    - suggest-tasks prioriza overdue > doing > todo
    - add-to-plan adiciona task ao plano
    - list-projects filtra por status
    - Teste Feature para cada tool

Arquivos:
    - app/Mcp/Tools/TodayPlanTool.php
    - app/Mcp/Tools/SuggestTasksTool.php
    - app/Mcp/Tools/AddToPlanTool.php
    - app/Mcp/Tools/ListProjectsTool.php
    - app/Mcp/Servers/SoloBoardServer.php (atualizar)
    - tests/Feature/Mcp/PlanAndProjectToolsTest.php

Commit: "feat: MCP tools for daily plan and project listing"
```

---

## Task 10.5 — MCP Resource + Prompt + Documentação de configuração

```yaml
Prompt: |
    Crie um MCP Resource e um Prompt para o SoloBoardServer:

    1. Resource: ProjectOverviewResource
       - URI: soloboard://overview
       - Retorna resumo do estado atual:
         - Projetos ativos com task counts por status
         - Timer ativo (se existir)
         - Tasks overdue
         - Horas trabalhadas hoje
       - Annotation: #[IsReadOnly], #[Priority(0.8)]

    2. Prompt: DailyPlanningPrompt
       - Description: "Helps plan the developer's day based on current tasks and priorities"
       - Arguments: focus_project (string, optional) — slug do projeto para focar
       - Retorna system message + user message com contexto das tasks,
         overdue items, e sugestões de priorização

    Registre ambos no SoloBoardServer.

    Atualize o CLAUDE.md com instruções de configuração do MCP:
```

## MCP Server

- Configurar: claude mcp add --transport http soloboard http://soloboard.test/mcp
- Header: Authorization: Bearer {SOLOBOARD_MCP_KEY}
- Tools: list-tasks, get-task, create-task, update-task, delete-task,
  start-timer, stop-timer, timer-status,
  today-plan, suggest-tasks, add-to-plan, list-projects

```

Acceptance Criteria:
- Resource retorna overview correto
- Prompt gera contexto útil para planejamento
- CLAUDE.md atualizado com instruções MCP
- Teste Feature: resource e prompt

Arquivos:
- app/Mcp/Resources/ProjectOverviewResource.php
- app/Mcp/Prompts/DailyPlanningPrompt.php
- app/Mcp/Servers/SoloBoardServer.php (atualizar)
- CLAUDE.md (atualizar)
- tests/Feature/Mcp/ResourceAndPromptTest.php

Commit: "feat: MCP resource, prompt, and configuration docs"
```
