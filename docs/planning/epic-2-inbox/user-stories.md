---
epic: "Epic 2: Inbox & Captura Rapida"
description: "Sistema de Inbox e Captura Rapida do SoloBoard — modal global com hotkey N, parsing de sintaxe inline (#projeto, !prioridade, @data), pagina de triagem de tasks inbox, e badge reativo na sidebar."
created_at: "2026-02-13"
total_stories: 7
complexity_summary:
  small: 3
  medium: 3
  large: 1
type_summary:
  core: 3
  frontend: 3
  both: 1
---

# User Stories: Epic 2 — Inbox & Captura Rapida

## Visao Geral

Este epic implementa o fluxo de captura rapida de tasks no SoloBoard. O usuario pode pressionar `N` de qualquer tela para abrir um modal de criacao rapida com sintaxe inline inteligente (`#projeto`, `!prioridade`, `@data`). As tasks criadas caem no Inbox, onde podem ser triadas (atribuir projeto, mover para backlog, deletar). Um badge reativo na sidebar mostra a contagem de tasks no inbox em tempo real.

**Pre-requisito critico:** Os Models, Enums, Factories e Migrations do Epic 1 nao existem no filesystem — apenas as tabelas no banco SQLite. Precisam ser criados antes de qualquer implementacao funcional.

## Ordem de Execucao

1. **US-000** — Pre-requisitos: Enums (core)
2. **US-001** — Pre-requisitos: Models e Factories (core)
3. **US-002** — Pre-requisitos: Migrations (core)
4. **US-003** — TaskQuickAdd: Criacao basica via modal (both)
5. **US-004** — TaskQuickAdd: Parsing de sintaxe inline e autocomplete (frontend)
6. **US-005** — Pagina Inbox com listagem e acoes (frontend)
7. **US-006** — Badge reativo do Inbox na sidebar (frontend)

---

## Lista de User Stories

### US-000: Criar Enums de dominio (TaskStatus, TaskPriority, ProjectStatus, ProjectPriority)

**Como** desenvolvedor
**Quero** ter Enums PHP nativos para status e prioridade de tasks e projetos
**Para** garantir type-safety nos casts dos Models e validacao consistente em toda a aplicacao

**Criterios de Aceitacao:**

- [ ] `app/Enums/TaskStatus.php` existe com cases: `Inbox`, `Backlog`, `Todo`, `Doing`, `Done`
- [ ] Cada case de `TaskStatus` possui metodos `label(): string` (PT-BR), `color(): string` e `icon(): string`
- [ ] `app/Enums/TaskPriority.php` existe com cases: `Urgent`, `High`, `Medium`, `Low`
- [ ] Cada case de `TaskPriority` possui metodos `label(): string` (PT-BR) e `color(): string`
- [ ] `app/Enums/ProjectStatus.php` existe com cases: `Active`, `Paused`, `Archived`
- [ ] Cada case de `ProjectStatus` possui metodo `label(): string` (PT-BR)
- [ ] `app/Enums/ProjectPriority.php` existe com cases: `High`, `Medium`, `Low`
- [ ] Cada case de `ProjectPriority` possui metodo `label(): string` (PT-BR)
- [ ] Todos os Enums sao `string` backed enums com valores lowercase (ex: `case Inbox = 'inbox'`)
- [ ] Testes unitarios validam que os Enums retornam labels, cores e icones corretos

**Complexidade:** P
**Estimativa:** 1h
**Dependencias:** Nenhuma
**Tipo:** core

**Arquivos afetados:**
- `app/Enums/TaskStatus.php` (criar)
- `app/Enums/TaskPriority.php` (criar)
- `app/Enums/ProjectStatus.php` (criar)
- `app/Enums/ProjectPriority.php` (criar)
- `tests/Unit/Enums/TaskStatusTest.php` (criar)
- `tests/Unit/Enums/TaskPriorityTest.php` (criar)

---

### US-001: Criar Models Project e Task com relationships e Factories

**Como** desenvolvedor
**Quero** ter Models Eloquent para Project e Task com relationships, casts, scopes e Factories
**Para** poder manipular dados de projetos e tasks via Eloquent e criar dados de teste com Factories

**Criterios de Aceitacao:**

- [ ] `app/Models/Project.php` existe com: `$fillable` (name, slug, color, emoji, status, priority, description), casts para enums (`status` → `ProjectStatus`, `priority` → `ProjectPriority`), scope `active()`, relationship `tasks(): HasMany`
- [ ] `app/Models/Task.php` existe com: `$fillable` (project_id, title, description, status, priority, due_date, estimated_minutes, completed_at, sort_order), casts para enums (`status` → `TaskStatus`, `priority` → `TaskPriority`), casts para datas (`due_date` → `date`, `completed_at` → `datetime`), scope `inbox()`, scope `byStatus(TaskStatus)`, relationship `project(): BelongsTo`, helpers `isOverdue(): bool` e `markAsDone(): void`
- [ ] `database/factories/ProjectFactory.php` existe com `definition()` gerando dados validos e states `active()`, `paused()`, `archived()`
- [ ] `database/factories/TaskFactory.php` existe com `definition()` gerando dados validos (status=inbox por default) e states `inbox()`, `backlog()`, `withProject()`, `overdue()`, `completed()`
- [ ] Given um Project com 3 tasks, When `$project->tasks` e acessado, Then retorna Collection com 3 Task models
- [ ] Given uma Task com project_id, When `$task->project` e acessado, Then retorna o Project model correto
- [ ] Given uma Task com due_date no passado e nao completada, When `$task->isOverdue()`, Then retorna `true`
- [ ] Given uma Task nao completada, When `$task->markAsDone()`, Then `status` muda para `Done` e `completed_at` e preenchido
- [ ] Testes Feature validam relationships, scopes, helpers e factories

**Complexidade:** M
**Estimativa:** 3h
**Dependencias:** US-000
**Tipo:** core

**Arquivos afetados:**
- `app/Models/Project.php` (criar)
- `app/Models/Task.php` (criar)
- `database/factories/ProjectFactory.php` (criar)
- `database/factories/TaskFactory.php` (criar)
- `tests/Feature/Models/ProjectTest.php` (criar)
- `tests/Feature/Models/TaskTest.php` (criar)

---

### US-002: Criar Migrations para tabelas projects, tasks e relacionadas

**Como** desenvolvedor
**Quero** ter arquivos de migration no filesystem para as tabelas projects, tasks, time_entries, daily_plans e daily_plan_task
**Para** que `RefreshDatabase` nos testes funcione corretamente e o projeto seja reprodutivel

**Criterios de Aceitacao:**

- [ ] Migration `create_projects_table` existe com schema identico ao banco atual (id, name, slug unique, color default '#6366f1', emoji default, status default 'active', priority default 'medium', description nullable, timestamps, indexes em status e priority)
- [ ] Migration `create_tasks_table` existe com schema identico ao banco atual (id, project_id nullable FK→projects ON DELETE SET NULL, title, description nullable, status default 'inbox', priority default 'medium', due_date nullable, estimated_minutes nullable, completed_at nullable, sort_order default 0, timestamps, indexes em status, priority, due_date, project_id)
- [ ] Migration `create_time_entries_table` existe com schema identico ao banco atual
- [ ] Migration `create_daily_plans_table` existe com schema identico ao banco atual
- [ ] Migration `create_daily_plan_task_table` existe com schema identico ao banco atual
- [ ] Given um ambiente limpo, When `php artisan migrate` e executado, Then todas as tabelas sao criadas sem erros
- [ ] Given testes com `RefreshDatabase`, When os testes rodam, Then as tabelas sao recriadas corretamente

**Complexidade:** M
**Estimativa:** 2h
**Dependencias:** Nenhuma (pode ser feita em paralelo com US-000)
**Tipo:** core

**Arquivos afetados:**
- `database/migrations/YYYY_MM_DD_HHMMSS_create_projects_table.php` (criar)
- `database/migrations/YYYY_MM_DD_HHMMSS_create_tasks_table.php` (criar)
- `database/migrations/YYYY_MM_DD_HHMMSS_create_time_entries_table.php` (criar)
- `database/migrations/YYYY_MM_DD_HHMMSS_create_daily_plans_table.php` (criar)
- `database/migrations/YYYY_MM_DD_HHMMSS_create_daily_plan_task_table.php` (criar)

---

### US-003: TaskQuickAdd — Criacao basica de task via modal global

**Como** usuario
**Quero** abrir um modal de qualquer tela pressionando `N` e criar uma task digitando o titulo e pressionando Enter
**Para** capturar ideias e tarefas rapidamente sem sair do contexto atual

**Criterios de Aceitacao:**

- [ ] Given o usuario esta em qualquer pagina autenticada e o foco NAO esta em input/textarea/select/contenteditable, When pressiona `N`, Then o modal `<flux:modal name="quick-add">` abre
- [ ] Given o modal esta aberto, When o usuario digita um titulo e pressiona `Enter`, Then uma task e criada com `status=inbox`, `priority=medium` e o titulo informado
- [ ] Given o modal esta aberto, When o usuario pressiona `Esc`, Then o modal fecha sem criar task
- [ ] Given uma task foi criada com sucesso, Then um toast de confirmacao e exibido (ex: "Task criada no inbox!")
- [ ] Given uma task foi criada com sucesso, Then o input do modal e limpo e o modal fecha
- [ ] Given o usuario esta digitando em um input de texto qualquer, When pressiona `N`, Then o modal NAO abre (a letra N e digitada normalmente)
- [ ] O componente `TaskQuickAdd` e um SFC incluido no layout `app.blade.php` para disponibilidade global
- [ ] Given uma task foi criada, Then o evento `task-created` e disparado para atualizar outros componentes
- [ ] Testes Feature validam: criacao de task via Livewire action, task criada com status inbox, validacao de titulo obrigatorio

**Complexidade:** M
**Estimativa:** 3h
**Dependencias:** US-000, US-001, US-002
**Tipo:** both

**Arquivos afetados:**
- `resources/views/components/task-quick-add.blade.php` (criar — SFC)
- `resources/views/layouts/app.blade.php` (editar — incluir componente)
- `tests/Feature/TaskQuickAddTest.php` (criar)

---

### US-004: TaskQuickAdd — Parsing de sintaxe inline e autocomplete

**Como** usuario
**Quero** usar sintaxe inline no input do QuickAdd (`#projeto`, `!prioridade`, `@data`) com autocomplete
**Para** definir projeto, prioridade e data de entrega sem sair do campo de texto

**Criterios de Aceitacao:**

- [ ] Given o usuario digita `#` no input, Then um dropdown de autocomplete aparece com projetos ativos (slug e nome)
- [ ] Given o usuario seleciona um projeto do autocomplete (ex: `#website-redesign`), Then o projeto e associado a task ao criar
- [ ] Given o usuario digita `#slug-inexistente`, When cria a task, Then um toast aviso "Projeto #slug-inexistente nao encontrado" e exibido e a task e criada sem projeto
- [ ] Given o usuario digita `!` no input, Then um dropdown de autocomplete aparece com prioridades (urgent, high, medium, low)
- [ ] Given o usuario seleciona `!urgent`, Then a task e criada com `priority=urgent`
- [ ] Given o usuario digita `@` no input, Then um dropdown de autocomplete aparece com opcoes de data (@hoje, @amanha, @segunda, @proxima-semana)
- [ ] Given o usuario seleciona `@hoje`, Then a task e criada com `due_date` = data de hoje
- [ ] Given o usuario seleciona `@amanha`, Then a task e criada com `due_date` = data de amanha
- [ ] Given o usuario seleciona `@segunda`, Then a task e criada com `due_date` = proxima segunda-feira
- [ ] Given o usuario seleciona `@proxima-semana`, Then a task e criada com `due_date` = proxima segunda-feira
- [ ] Given o input contem `Revisar PR #api-integration !high @amanha`, When cria a task, Then titulo = "Revisar PR", projeto = api-integration, prioridade = high, due_date = amanha
- [ ] Os tokens de sintaxe (`#...`, `!...`, `@...`) sao removidos do titulo final da task
- [ ] Testes Feature validam: parsing de cada prefixo isolado, parsing combinado, slug inexistente, datas relativas

**Complexidade:** G
**Estimativa:** 5h
**Dependencias:** US-003
**Tipo:** frontend

**Arquivos afetados:**
- `resources/views/components/task-quick-add.blade.php` (editar — adicionar parsing e autocomplete)
- `tests/Feature/TaskQuickAddTest.php` (editar — adicionar testes de parsing)

---

### US-005: Pagina Inbox com listagem e acoes de triagem

**Como** usuario
**Quero** ver todas as tasks no inbox e poder triaga-las (atribuir projeto, mover para backlog, deletar)
**Para** organizar as tasks capturadas e decidir o proximo passo de cada uma

**Criterios de Aceitacao:**

- [ ] Given o usuario acessa `/inbox`, Then ve a lista de tasks com `status=inbox` ordenadas por `latest` (mais recentes primeiro)
- [ ] Cada card de task exibe: titulo, tempo desde criacao (`diffForHumans` em PT-BR, ex: "ha 5 minutos"), projeto associado (se tiver, com emoji e nome)
- [ ] Given uma task no inbox, When o usuario seleciona um projeto no `<flux:select>` inline, Then o `project_id` da task e atualizado
- [ ] Given uma task no inbox, When o usuario clica em "Backlog", Then o status da task muda para `backlog` e ela desaparece da lista
- [ ] Given uma task no inbox, When o usuario clica em "Excluir", Then um modal de confirmacao `<flux:modal>` aparece
- [ ] Given o modal de confirmacao esta aberto, When o usuario confirma, Then a task e deletada e desaparece da lista
- [ ] Given nao existem tasks no inbox, Then o empty state e exibido com icone inbox e texto "Nenhuma task no inbox. Pressione N para criar."
- [ ] A rota `/inbox` requer autenticacao (`middleware('auth')`) e tem nome `inbox`
- [ ] Given o usuario nao esta autenticado, When acessa `/inbox`, Then e redirecionado para login
- [ ] Given uma acao e realizada (mover, deletar, atribuir projeto), Then o evento `inbox-updated` e disparado para atualizar o badge da sidebar
- [ ] Tasks sao carregadas com eager loading de `project` (sem N+1)
- [ ] Testes Feature validam: listagem, atribuir projeto, mover para backlog, deletar, empty state, autenticacao

**Complexidade:** M
**Estimativa:** 4h
**Dependencias:** US-000, US-001, US-002
**Tipo:** frontend

**Arquivos afetados:**
- `resources/views/pages/inbox.blade.php` (criar — SFC com prefixo ⚡)
- `routes/web.php` (editar — adicionar rota /inbox)
- `tests/Feature/InboxTest.php` (criar)

---

### US-006: Badge reativo do Inbox na sidebar

**Como** usuario
**Quero** ver um badge na sidebar mostrando a quantidade de tasks no inbox, atualizado em tempo real
**Para** saber rapidamente quantas tasks preciso triar sem precisar abrir a pagina do inbox

**Criterios de Aceitacao:**

- [ ] O item "Inbox" na sidebar exibe um badge numerico com a contagem de tasks com `status=inbox`
- [ ] Given existem 5 tasks no inbox, Then o badge exibe "5"
- [ ] Given nao existem tasks no inbox, Then o badge NAO e exibido (ou exibe "0" de forma discreta)
- [ ] Given uma task e criada via QuickAdd (evento `task-created`), Then o badge atualiza automaticamente sem reload
- [ ] Given uma task e movida para backlog na pagina Inbox (evento `inbox-updated`), Then o badge atualiza automaticamente
- [ ] Given uma task e deletada na pagina Inbox (evento `inbox-updated`), Then o badge atualiza automaticamente
- [ ] O link do item Inbox na sidebar aponta para `route('inbox')` (atualmente aponta para `route('dashboard')`)
- [ ] O badge usa componente Livewire (SFC ou inline) que escuta eventos para reatividade
- [ ] Testes Feature validam: contagem correta, atualizacao via evento

**Complexidade:** P
**Estimativa:** 2h
**Dependencias:** US-005
**Tipo:** frontend

**Arquivos afetados:**
- `resources/views/layouts/app/sidebar.blade.php` (editar — adicionar badge e corrigir href)
- `resources/views/components/inbox-badge.blade.php` (criar — SFC para badge reativo)
- `tests/Feature/InboxBadgeTest.php` (criar)

---

## Resumo de Complexidade

| Complexidade | Quantidade | User Stories |
|-------------|-----------|--------------|
| P (Pequeno) | 3 | US-000, US-006 |
| M (Medio)   | 3 | US-001, US-002, US-003, US-005 |
| G (Grande)  | 1 | US-004 |

**Estimativa total:** ~20h

## Grafo de Dependencias

```
US-000 (Enums) ──────┐
                      ├──→ US-001 (Models/Factories) ──┐
US-002 (Migrations) ──┤                                 ├──→ US-003 (QuickAdd basico) ──→ US-004 (Parsing/Autocomplete)
                      │                                 │
                      └─────────────────────────────────┴──→ US-005 (Pagina Inbox) ──→ US-006 (Badge sidebar)
```

## Notas Tecnicas

1. **SFC Pattern**: Todos os componentes Livewire seguem o padrao Single-File Component com `new class extends Component {}` no topo do arquivo Blade. Nao criar arquivos PHP separados em `app/Livewire/`.

2. **Migrations**: As tabelas ja existem no banco SQLite. As migrations devem ser criadas para reprodutibilidade e para que `RefreshDatabase` funcione nos testes. O timestamp das migrations deve ser anterior ao das migrations existentes no banco para manter a ordem correta.

3. **Eventos Livewire**: Usar `$this->dispatch('task-created')` e `$this->dispatch('inbox-updated')` para comunicacao entre componentes (QuickAdd → Badge, Inbox → Badge).

4. **Autocomplete**: Abordagem hibrida recomendada — carregar projetos ativos no `mount()` do componente, autocomplete via Alpine.js para responsividade.

5. **Locale PT-BR**: Labels de Enums, toasts, empty states e `diffForHumans` devem estar em portugues. Codigo (variaveis, metodos, classes) em ingles.
