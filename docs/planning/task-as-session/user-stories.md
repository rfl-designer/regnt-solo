# User Stories: Epic 15 — Task-as-Session

## Visao Geral

Este epic modela tasks como sessoes de AI coding, onde **1 task = 1 sessao = 1 PR**. As User Stories estao organizadas em ordem de dependencia: primeiro o backend (migration, model, factory, seeder, MCP tools, MCP prompt), depois o frontend (task modal, quick-add, kanban badge).

**Total de User Stories:** 7
**Estimativa Total:** ~14.5 horas
**Core (lara-core):** 4 stories (US-001 a US-004)
**Frontend (wire-maker):** 3 stories (US-005 a US-007)

## Ordem de Execucao

```
US-001 (Migration + Model)
  └── US-002 (Factory + Seeder)
  └── US-003 (MCP Tools)
       └── US-004 (MCP Prompt)
  └── US-005 (Task Modal Session View)
  └── US-006 (Quick-Add Session)
  └── US-007 (Kanban Badge)
```

> US-001 e pre-requisito para todas as demais. US-002 a US-007 dependem apenas de US-001 (exceto US-004 que depende de US-003). US-005, US-006 e US-007 sao independentes entre si.

---

## Lista de User Stories

### US-001: Migration e Model — Session Fields

**Como** desenvolvedor solo
**Quero** que a tabela `tasks` tenha campos `session_prompt` e `session_result`, e que o model Task tenha metodos para identificar e resumir session tasks
**Para** que o sistema possa distinguir tasks normais de session tasks e fornecer dados estruturados sobre sessoes

**Criterios de Aceitacao:**

- [ ] Migration `add_session_fields_to_tasks_table` criada com `session_prompt` (text, nullable, after pr_url) e `session_result` (text, nullable, after session_prompt)
- [ ] Migration roda com sucesso em SQLite (`php artisan migrate`)
- [ ] Migration tem metodo `down()` que remove ambas as colunas
- [ ] `session_prompt` e `session_result` adicionados ao `$fillable` do model Task
- [ ] Metodo `isSessionTask(): bool` retorna `true` quando `session_prompt` nao e null
- [ ] Metodo `isSessionTask(): bool` retorna `false` quando `session_prompt` e null
- [ ] Metodo `sessionSummary(): array` retorna array com keys: `is_session`, `prompt`, `result`, `pr_url`, `commits_count`, `files_changed`, `status`
- [ ] `sessionSummary()` reutiliza `commitCount()` e `totalFilesChanged()` existentes
- [ ] Tasks existentes (sem session data) continuam funcionando normalmente — nenhum teste existente quebra
- [ ] Testes Feature criados em `tests/Feature/TaskSessionTest.php` cobrindo: `isSessionTask()` true/false, `sessionSummary()` com e sem dados, compatibilidade com tasks normais

**Complexidade:** P (Pequeno)
**Estimativa:** ~1.5 horas
**Dependencias:** Nenhuma
**Tipo:** `core`

**Arquivos a Criar:**
- `database/migrations/XXXX_add_session_fields_to_tasks_table.php`
- `tests/Feature/TaskSessionTest.php`

**Arquivos a Modificar:**
- `app/Models/Task.php` — adicionar fillable, `isSessionTask()`, `sessionSummary()`

---

### US-002: Factory State e Seeder — Session Data

**Como** desenvolvedor solo
**Quero** que a factory de Task tenha um state `->session()` e que o seeder inclua tasks com session data
**Para** que eu possa criar session tasks facilmente em testes e ter dados de exemplo para desenvolvimento

**Criterios de Aceitacao:**

- [ ] Factory state `->session()` criado em `TaskFactory` que preenche `session_prompt` com `fake()->paragraph()` e `session_result` com `fake()->optional(0.7)->paragraph()`
- [ ] State `->session()` e combinavel com outros states existentes (ex: `Task::factory()->session()->doing()->create()`)
- [ ] Seeder atualizado com 2-3 tasks com session data: pelo menos 1 com status `doing` e 1 com status `done`
- [ ] Session tasks no seeder tem dados realistas em PT-BR (prompts descritivos de sessoes de coding)
- [ ] `php artisan migrate:fresh --seed` roda sem erros
- [ ] Teste Feature valida que factory state `->session()` cria task com `isSessionTask() === true`
- [ ] Teste Feature valida que factory state `->session()` combinado com `->done()` funciona corretamente

**Complexidade:** P (Pequeno)
**Estimativa:** ~1 hora
**Dependencias:** US-001
**Tipo:** `core`

**Arquivos a Modificar:**
- `database/factories/TaskFactory.php` — adicionar state `->session()`
- `database/seeders/DatabaseSeeder.php` — adicionar session tasks
- `tests/Feature/TaskSessionTest.php` — adicionar testes de factory

---

### US-003: MCP Tools — Session Fields no CreateTask, UpdateTask e GetTask

**Como** assistente AI usando o MCP server
**Quero** poder criar tasks com `session_prompt`, atualizar `session_prompt`/`session_result`, e ver o `session_summary` ao consultar uma task
**Para** que eu possa gerenciar sessoes de coding completas via MCP tools

**Criterios de Aceitacao:**

- [ ] `CreateTaskTool`: aceita `session_prompt` (string, opcional) no schema e validation
- [ ] `CreateTaskTool`: quando `session_prompt` e fornecido, a task criada tem `isSessionTask() === true`
- [ ] `CreateTaskTool`: retorno inclui `is_session_task: true/false`
- [ ] `UpdateTaskTool`: aceita `session_prompt` e `session_result` (strings, opcionais) no schema e validation
- [ ] `UpdateTaskTool`: permite atualizar `session_prompt` e `session_result` independentemente
- [ ] `GetTaskTool`: retorno inclui `session_summary` (usando `$task->sessionSummary()`)
- [ ] Testes Feature em `tests/Feature/Mcp/TaskToolsTest.php`:
  - Criar task com `session_prompt` via CreateTaskTool
  - Criar task sem `session_prompt` (comportamento existente inalterado)
  - Atualizar `session_result` via UpdateTaskTool
  - GetTask retorna `session_summary` para session task
  - GetTask retorna `session_summary` com `is_session: false` para task normal
- [ ] Todos os testes MCP existentes continuam passando

**Complexidade:** M (Medio)
**Estimativa:** ~2.5 horas
**Dependencias:** US-001
**Tipo:** `core`

**Arquivos a Modificar:**
- `app/Mcp/Tools/CreateTaskTool.php` — adicionar `session_prompt` ao schema, validation e create
- `app/Mcp/Tools/UpdateTaskTool.php` — adicionar `session_prompt` e `session_result` ao schema, validation e update
- `app/Mcp/Tools/GetTaskTool.php` — incluir `session_summary` no retorno
- `tests/Feature/Mcp/TaskToolsTest.php` — adicionar testes para session fields

---

### US-004: MCP Prompt — SessionPlanningPrompt

**Como** assistente AI usando o MCP server
**Quero** ter um prompt `SessionPlanningPrompt` que gere contexto para planejar uma sessao de coding
**Para** que eu possa receber instrucoes estruturadas e contexto relevante ao iniciar uma sessao de desenvolvimento

**Criterios de Aceitacao:**

- [ ] Classe `SessionPlanningPrompt` criada em `app/Mcp/Prompts/SessionPlanningPrompt.php` seguindo o padrao do `DailyPlanningPrompt`
- [ ] Argumento obrigatorio: `task_id` (integer) — ID da task session
- [ ] Valida que a task existe e e uma session task (`isSessionTask() === true`)
- [ ] Contexto inclui: prompt da task, projeto (se houver), tasks relacionadas do mesmo projeto, commits existentes, status atual
- [ ] System message: instrucoes para o AI planejar a sessao de coding (em ingles, seguindo padrao existente)
- [ ] User message: contexto formatado com dados da task e projeto
- [ ] `SessionPlanningPrompt` registrado no array `$prompts` do `SoloBoardServer`
- [ ] Testes Feature em `tests/Feature/Mcp/SessionPlanningPromptTest.php`:
  - Prompt retorna contexto para session task valida
  - Prompt inclui dados do projeto quando task tem projeto
  - Prompt inclui commits quando existem
  - Prompt falha para task_id inexistente
  - Prompt funciona para session task sem projeto

**Complexidade:** M (Medio)
**Estimativa:** ~2.5 horas
**Dependencias:** US-001, US-003
**Tipo:** `core`

**Arquivos a Criar:**
- `app/Mcp/Prompts/SessionPlanningPrompt.php`
- `tests/Feature/Mcp/SessionPlanningPromptTest.php`

**Arquivos a Modificar:**
- `app/Mcp/Servers/SoloBoardServer.php` — registrar `SessionPlanningPrompt` no array `$prompts`

---

### US-005: Task Modal — Session View

**Como** desenvolvedor solo
**Quero** que o Task Modal exiba um layout especial quando a task e uma session task, mostrando o prompt da sessao e o resultado
**Para** que eu possa visualizar e editar os dados da sessao de coding diretamente no modal

**Criterios de Aceitacao:**

- [ ] Task Modal detecta `isSessionTask()` e exibe secoes adicionais condicionalmente
- [ ] Secao "Prompt da Sessao" exibe o `session_prompt` em bloco estilizado (read-only, fundo `zinc-800/50`, borda `zinc-700`)
- [ ] Secao "Resultado" exibe campo editavel com `session_result` (usando `<flux:textarea>` ou `<flux:editor>` com `wire:model`)
- [ ] Salvar o modal persiste alteracoes no `session_result`
- [ ] Tasks normais (nao-session) nao exibem as secoes de session — comportamento inalterado
- [ ] Labels em PT-BR: "Prompt da Sessao", "Resultado"
- [ ] Dark mode: cores consistentes com o restante do modal (`zinc-700/800/900`)
- [ ] Testes Feature em `tests/Feature/TaskModalTest.php`:
  - Modal exibe secao de prompt para session task
  - Modal nao exibe secao de prompt para task normal
  - Pode salvar `session_result` via modal
  - Secao de resultado e editavel

**Complexidade:** M (Medio)
**Estimativa:** ~3 horas
**Dependencias:** US-001
**Tipo:** `frontend`

**Arquivos a Modificar:**
- `resources/views/components/⚡task-modal.blade.php` — adicionar session view condicional (propriedades PHP, secoes Blade)
- `tests/Feature/TaskModalTest.php` — adicionar testes para session view

---

### US-006: Quick-Add — Prefixo `>` para Session Task

**Como** desenvolvedor solo
**Quero** poder criar uma session task rapidamente usando o prefixo `>` no Quick-Add
**Para** que eu possa iniciar uma sessao de coding com um unico input, sem precisar abrir o modal

**Criterios de Aceitacao:**

- [ ] Input comecando com `>` cria uma session task: texto apos `>` vira `session_prompt`
- [ ] Titulo da task e derivado do prompt (primeiras palavras, truncado se necessario, max 255 chars)
- [ ] Prefixo `>` e combinavel com outros prefixos existentes: `>Implementar auth #api-gateway !high`
- [ ] Task criada com `session_prompt` preenchido e `isSessionTask() === true`
- [ ] Input sem prefixo `>` continua criando task normal — comportamento inalterado
- [ ] Input vazio apos `>` (ex: `>` ou `> `) nao cria task
- [ ] Toast de sucesso indica que session task foi criada (ex: "Sessao criada!")
- [ ] Testes Feature em `tests/Feature/TaskQuickAddTest.php`:
  - Criar session task com `>prompt text`
  - Combinar `>` com `#projeto` e `!prioridade`
  - Input `>` sozinho nao cria task
  - Task normal sem `>` continua funcionando

**Complexidade:** M (Medio)
**Estimativa:** ~2 horas
**Dependencias:** US-001
**Tipo:** `frontend`

**Arquivos a Modificar:**
- `resources/views/components/⚡task-quick-add.blade.php` — adicionar deteccao de prefixo `>` no `createTask()`
- `tests/Feature/TaskQuickAddTest.php` — adicionar testes para prefixo `>`

---

### US-007: Kanban Card — Badge para Session Tasks

**Como** desenvolvedor solo
**Quero** ver um badge visual nos cards do Kanban quando a task e uma session task
**Para** que eu possa identificar rapidamente quais tasks sao sessoes de coding no board

**Criterios de Aceitacao:**

- [ ] Cards de session tasks exibem badge `<flux:badge size="sm" color="violet">` com texto "Sessao" (ou icone 🤖)
- [ ] Badge posicionado junto aos badges existentes (prioridade, overdue, etc.)
- [ ] Tasks normais nao exibem o badge — comportamento inalterado
- [ ] Badge usa `$task->isSessionTask()` que nao requer eager loading extra (checa `session_prompt !== null`)
- [ ] Visual consistente com dark mode e badges existentes
- [ ] Teste Feature valida que badge aparece para session task no kanban
- [ ] Teste Feature valida que badge nao aparece para task normal no kanban

**Complexidade:** P (Pequeno)
**Estimativa:** ~1 hora
**Dependencias:** US-001
**Tipo:** `frontend`

**Arquivos a Modificar:**
- `resources/views/pages/⚡kanban.blade.php` — adicionar badge condicional nos cards
- Teste Feature (novo ou existente) para validar badge no kanban

---

## Resumo de Complexidade

| Complexidade | Quantidade | Stories |
|-------------|-----------|---------|
| P (Pequeno) | 3 | US-001, US-002, US-007 |
| M (Medio) | 4 | US-003, US-004, US-005, US-006 |
| G (Grande) | 0 | — |

## Mapa de Dependencias

```
US-001 ──┬── US-002
         ├── US-003 ── US-004
         ├── US-005
         ├── US-006
         └── US-007
```

## Notas de Implementacao

1. **Compatibilidade**: Todos os campos session sao nullable. `isSessionTask()` retorna false por padrao. Nenhuma funcionalidade existente deve ser afetada.
2. **SFC Pattern**: O Task Modal e Quick-Add usam Single-File Components (PHP + Blade no mesmo arquivo). Nao criar arquivos PHP separados em `app/Livewire/`.
3. **Testes Existentes**: Rodar `php artisan test --compact` apos cada US para garantir que nenhum teste existente quebrou.
4. **Pint**: Rodar `vendor/bin/pint --dirty --format agent` apos cada US para manter formatacao.
5. **PT-BR**: Labels e mensagens de interface em portugues. Codigo (variaveis, metodos, classes) em ingles.
6. **Dark Mode Only**: Usar cores `zinc-700/800/900` consistentes com o restante da aplicacao.
