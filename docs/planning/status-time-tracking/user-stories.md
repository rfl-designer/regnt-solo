---
epic: "Epic 11: Status Time Tracking Automatico"
description: "Rastreamento automatico de quanto tempo cada task passa em cada status (Inbox, Backlog, Todo, Doing, Done) via Observer, com barra segmentada no Task Modal, metricas no Dashboard e timeline no MCP GetTaskTool."
created_at: "2026-02-15"
total_stories: 7
complexity_summary:
  small: 2
  medium: 3
  large: 2
type_summary:
  core: 4
  frontend: 2
  both: 1
---

# User Stories: Epic 11 -- Status Time Tracking Automatico

## Visao Geral

Este epic implementa o rastreamento automatico de tempo por status para tasks do SoloBoard. Um Observer captura automaticamente cada mudanca de status (sem acao do usuario), registrando timestamps na tabela `task_status_changes`. Accessors no model Task calculam tempo em cada status e duracao no status atual. A visualizacao inclui uma barra horizontal segmentada no Task Modal, metricas de tempo medio no Dashboard (que hoje e placeholder), e timeline de status no MCP GetTaskTool.

**Pre-requisitos:** Model Task, Enum TaskStatus (5 valores), Task Modal SFC, Dashboard SFC (placeholder), GetTaskTool MCP — todos ja existem. Nao existem Observers no projeto — este sera o primeiro, usando `#[ObservedBy]` (Laravel 12).

## Ordem de Execucao

1. **US-001** -- Migration + Model TaskStatusChange (core)
2. **US-002** -- Observer TaskObserver com registro automatico (core)
3. **US-003** -- Relationship e Accessors no Task model (core)
4. **US-004** -- Seeder com historico retroativo (core)
5. **US-005** -- Barra segmentada no Task Modal (frontend)
6. **US-006** -- Metricas de tempo no Dashboard (both)
7. **US-007** -- Timeline no MCP GetTaskTool (frontend)

---

## Lista de User Stories

### US-001: Migration e Model TaskStatusChange

**Como** desenvolvedor
**Quero** uma tabela e model para registrar mudancas de status das tasks
**Para** ter a infraestrutura de dados necessaria para calcular tempo por status

**Criterios de Aceitacao:**

- [ ] Migration `create_task_status_changes_table` cria tabela com: `id`, `task_id` (FK com cascade delete), `status` (string), `changed_at` (timestamp), `timestamps`
- [ ] Indice composto em `['task_id', 'changed_at']` para queries performaticas
- [ ] Model `TaskStatusChange` com `$fillable`: `task_id`, `status`, `changed_at`
- [ ] Cast `status` para `TaskStatus` enum e `changed_at` para `datetime` no metodo `casts()`
- [ ] Relationship `task()` retorna `BelongsTo` para `Task`
- [ ] Factory `TaskStatusChangeFactory` com definition basica e states: `inbox()`, `backlog()`, `todo()`, `doing()`, `done()` para cada status
- [ ] Given migration executada, When inspecionar schema, Then tabela existe com colunas e FK corretas
- [ ] Teste Feature: model pode ser criado via factory, casts funcionam, relationship task() retorna Task

**Complexidade:** P
**Estimativa:** 1.5h
**Dependencias:** Nenhuma
**Tipo:** core

**Arquivos afetados:**
- `database/migrations/xxxx_create_task_status_changes_table.php` (criar)
- `app/Models/TaskStatusChange.php` (criar)
- `database/factories/TaskStatusChangeFactory.php` (criar)
- `tests/Feature/TaskStatusChangeTest.php` (criar)

---

### US-002: Observer TaskObserver com registro automatico de mudancas

**Como** usuario
**Quero** que cada mudanca de status das minhas tasks seja registrada automaticamente
**Para** ter um historico completo sem precisar fazer nada manualmente

**Criterios de Aceitacao:**

- [ ] Observer `TaskObserver` criado em `app/Observers/TaskObserver.php`
- [ ] Registrado via atributo `#[ObservedBy(TaskObserver::class)]` no model Task (padrao Laravel 12)
- [ ] Evento `created`: registra `TaskStatusChange` com status inicial da task e `changed_at` = `created_at` da task (ou `now()` se null)
- [ ] Evento `updating`: se `$task->isDirty('status')`, registra `TaskStatusChange` com o NOVO status e `changed_at` = `now()`
- [ ] Evento `updating`: se status NAO mudou, nao cria registro (evita duplicatas)
- [ ] Given task criada com status Inbox, When verificar task_status_changes, Then existe 1 registro com status=inbox
- [ ] Given task com status Todo atualizada para Doing, When verificar task_status_changes, Then existe registro com status=doing e changed_at recente
- [ ] Given task atualizada apenas no titulo (sem mudar status), When verificar task_status_changes, Then nenhum novo registro e criado
- [ ] Given `markAsDone()` chamado, When verificar task_status_changes, Then registro com status=done e criado (observer captura o `update()` interno)
- [ ] Suite completa de testes existentes (36 testes) continua passando apos registrar o observer
- [ ] Teste Feature: criacao registra status inicial, update de status registra mudanca, update sem status nao registra, markAsDone registra

**Complexidade:** M
**Estimativa:** 3h
**Dependencias:** US-001
**Tipo:** core

**Arquivos afetados:**
- `app/Observers/TaskObserver.php` (criar)
- `app/Models/Task.php` (editar -- adicionar `#[ObservedBy]`)
- `tests/Feature/TaskStatusChangeTest.php` (editar -- adicionar testes do observer)

---

### US-003: Relationship e Accessors de tempo no Task model

**Como** usuario
**Quero** consultar quanto tempo cada task passou em cada status
**Para** entender o fluxo de trabalho e identificar gargalos

**Criterios de Aceitacao:**

- [ ] Relationship `statusChanges()` no Task retorna `HasMany` ordenado por `changed_at` asc
- [ ] Accessor `time_per_status` retorna `array<string, float>` com minutos em cada status (chave = valor do enum, ex: `['inbox' => 120.0, 'todo' => 60.0]`)
- [ ] Accessor `time_per_status` calcula tempo entre mudancas consecutivas: tempo no status X = diff entre `changed_at` do registro X e `changed_at` do registro seguinte
- [ ] Accessor `time_per_status` inclui tempo no status atual (ultimo registro ate `now()`)
- [ ] Accessor `time_per_status` retorna array vazio `[]` quando task nao tem historico de status
- [ ] Accessor `time_per_status` acumula tempo se task voltou para um status anterior (ex: Todo -> Doing -> Todo soma tempo em Todo)
- [ ] Accessor `current_status_duration` retorna `float` com minutos no status atual (diff entre ultimo `changed_at` e `now()`)
- [ ] Accessor `current_status_duration` retorna `0.0` quando task nao tem historico
- [ ] Given task com 3 mudancas de status (Inbox 2h, Todo 1h, Doing atual 30min), When acessar `time_per_status`, Then retorna `['inbox' => 120.0, 'todo' => 60.0, 'doing' => 30.0]`
- [ ] Given task com status Doing ha 45 minutos, When acessar `current_status_duration`, Then retorna ~45.0
- [ ] Funciona corretamente com `CarbonImmutable` (projeto usa `Date::use(CarbonImmutable::class)`)
- [ ] Teste Feature: time_per_status com multiplas mudancas, status repetido acumula, sem historico retorna vazio, current_status_duration calcula corretamente

**Complexidade:** M
**Estimativa:** 3h
**Dependencias:** US-002
**Tipo:** core

**Arquivos afetados:**
- `app/Models/Task.php` (editar -- adicionar relationship e accessors)
- `tests/Feature/TaskStatusChangeTest.php` (editar -- adicionar testes dos accessors)

---

### US-004: Seeder com historico retroativo de status

**Como** desenvolvedor
**Quero** que o seeder gere historico de mudancas de status para as tasks existentes
**Para** ter dados realistas para visualizar as metricas de tempo no dashboard e task modal

**Criterios de Aceitacao:**

- [ ] Metodo `createStatusHistory(Task $task)` adicionado ao `DatabaseSeeder`
- [ ] Para cada task, gera mudancas de status seguindo o fluxo natural: Inbox -> Backlog -> Todo -> Doing -> Done (ate o status atual da task)
- [ ] Timestamps das mudancas sao retroativos e realistas: baseados no `created_at` da task, com intervalos aleatorios de 1-48 horas entre cada mudanca
- [ ] Tasks com status Done tem historico completo ate Done
- [ ] Tasks com status Inbox tem apenas 1 registro (status inicial)
- [ ] Observer e desabilitado durante o seeder (`Task::withoutEvents()`) para evitar duplicatas, ja que o seeder cria o historico manualmente
- [ ] Given seeder executado, When verificar task_status_changes, Then todas as 35 tasks tem historico coerente com seu status atual
- [ ] Given task com status Doing, When verificar historico, Then tem registros para Inbox, Backlog, Todo e Doing (nessa ordem cronologica)
- [ ] Teste Feature: seeder cria historico para todas as tasks, historico e coerente com status final

**Complexidade:** M
**Estimativa:** 2h
**Dependencias:** US-002
**Tipo:** core

**Arquivos afetados:**
- `database/seeders/DatabaseSeeder.php` (editar -- adicionar createStatusHistory)

---

### US-005: Barra segmentada de tempo por status no Task Modal

**Como** usuario
**Quero** ver uma barra visual no modal da task mostrando quanto tempo ela passou em cada status
**Para** entender rapidamente o ciclo de vida da task

**Criterios de Aceitacao:**

- [ ] Secao "Tempo por status" adicionada ao Task Modal, abaixo dos campos de edicao
- [ ] Barra horizontal segmentada (stacked bar) com largura proporcional ao tempo em cada status
- [ ] Cada segmento usa a cor do `TaskStatus::color()` correspondente (zinc, slate, blue, amber, emerald)
- [ ] Tooltip em cada segmento mostra: label do status (PT-BR) + tempo formatado (ex: "Fazendo: 2h 30min")
- [ ] Legenda abaixo da barra com icone + label + tempo de cada status presente
- [ ] Formatacao de tempo: < 60min mostra "Xmin", >= 60min mostra "Xh Ymin", >= 24h mostra "Xd Xh"
- [ ] Given task sem historico de status, Then secao nao aparece (graceful degradation)
- [ ] Given task com historico, Then barra renderiza com segmentos proporcionais e cores corretas
- [ ] Eager loading de `statusChanges` ao abrir o modal para evitar N+1
- [ ] Teste Feature: barra renderiza com dados corretos, secao esconde sem historico, cores correspondem aos status

**Complexidade:** G
**Estimativa:** 4h
**Dependencias:** US-003
**Tipo:** frontend

**Arquivos afetados:**
- `resources/views/components/⚡task-modal.blade.php` (editar -- adicionar secao de tempo)
- `tests/Feature/TaskModalTest.php` (editar -- adicionar testes da barra)

---

### US-006: Metricas de tempo medio no Dashboard

**Como** usuario
**Quero** ver metricas de tempo medio por status no dashboard
**Para** entender a velocidade do meu fluxo de trabalho e identificar gargalos

**Criterios de Aceitacao:**

- [ ] Dashboard deixa de ser placeholder e exibe cards de metricas reais
- [ ] Card "Tempo medio total": media de tempo entre primeira e ultima mudanca de status para tasks concluidas nos ultimos 30 dias (cycle time)
- [ ] Card "Tempo medio por coluna": para cada status (Inbox, Backlog, Todo, Doing), media de tempo que tasks concluidas passaram naquele status nos ultimos 30 dias
- [ ] Card "Tasks concluidas": quantidade de tasks concluidas nos ultimos 30 dias
- [ ] Metricas calculadas via queries agregadas no banco (nao carregar todos os models) para performance
- [ ] Filtro de periodo: ultimos 30 dias (hardcoded nesta US, extensivel futuramente)
- [ ] Given nenhuma task concluida nos ultimos 30 dias, Then cards exibem "Sem dados" ou valor zero
- [ ] Given 5 tasks concluidas com tempos variados, Then medias sao calculadas corretamente
- [ ] Formatacao de tempo consistente com US-005 (Xmin, Xh Ymin, Xd Xh)
- [ ] Labels e textos em PT-BR
- [ ] Teste Feature: metricas calculam corretamente com dados, exibem gracefully sem dados

**Complexidade:** G
**Estimativa:** 5h
**Dependencias:** US-003
**Tipo:** both

**Arquivos afetados:**
- `resources/views/pages/⚡dashboard.blade.php` (editar -- substituir placeholder por metricas)
- `tests/Feature/DashboardTest.php` (editar -- adicionar testes de metricas)

---

### US-007: Timeline de status no MCP GetTaskTool

**Como** assistente AI (via MCP)
**Quero** receber o historico de mudancas de status e tempo por status ao consultar uma task
**Para** fornecer contexto temporal completo ao usuario sobre o ciclo de vida da task

**Criterios de Aceitacao:**

- [ ] `GetTaskTool` inclui campo `status_timeline` no response: array de objetos com `status`, `changed_at` e `label` (PT-BR) ordenados cronologicamente
- [ ] `GetTaskTool` inclui campo `time_per_status` no response: objeto com status como chave e minutos como valor (ex: `{"inbox": 120, "doing": 45}`)
- [ ] `GetTaskTool` inclui campo `current_status_duration_minutes` no response: minutos no status atual
- [ ] Eager loading de `statusChanges` adicionado a query do GetTaskTool
- [ ] Given task com historico de 3 mudancas, When chamar get-task, Then response inclui timeline com 3 entries e time_per_status com tempos corretos
- [ ] Given task sem historico, Then `status_timeline` retorna array vazio, `time_per_status` retorna objeto vazio, `current_status_duration_minutes` retorna 0
- [ ] Teste Feature: response inclui timeline e time_per_status, dados corretos, graceful sem historico

**Complexidade:** P
**Estimativa:** 1.5h
**Dependencias:** US-003
**Tipo:** frontend

**Arquivos afetados:**
- `app/Mcp/Tools/GetTaskTool.php` (editar -- adicionar timeline e time_per_status)
- `tests/Feature/Mcp/TaskToolsTest.php` (editar -- adicionar testes do timeline)

---

## Resumo de Complexidade

| Complexidade | Quantidade | User Stories |
|-------------|-----------|--------------|
| P (Pequeno) | 2 | US-001, US-007 |
| M (Medio)   | 3 | US-002, US-003, US-004 |
| G (Grande)  | 2 | US-005, US-006 |

**Estimativa total:** ~20h

## Grafo de Dependencias

```
US-001 (Migration + Model)
  |
  v
US-002 (Observer) --------+
  |                        |
  +---> US-003 (Accessors) +---> US-004 (Seeder)
          |
          +---> US-005 (Barra no Modal)
          |
          +---> US-006 (Metricas Dashboard)
          |
          +---> US-007 (Timeline MCP)
```

## Notas Tecnicas

1. **Primeiro Observer do projeto**: Usar `#[ObservedBy(TaskObserver::class)]` no model Task (padrao Laravel 12). Nao registrar no AppServiceProvider.

2. **Evento `updating` vs `updated`**: Usar `updating` para registrar a mudanca ANTES de persistir. No `updating`, `$task->status` ja contem o novo valor e `$task->getOriginal('status')` retorna o antigo. Registrar o NOVO status.

3. **Impacto nos testes existentes**: O observer criara `TaskStatusChange` records como efeito colateral em todos os testes que criam/atualizam tasks. Isso nao deve causar problemas pois os records ficam em tabela separada. Rodar suite completa apos US-002.

4. **CarbonImmutable**: O projeto usa `Date::use(CarbonImmutable::class)`. Os accessors devem usar `->diffInMinutes()` que funciona igual em Carbon e CarbonImmutable.

5. **Seeder e Observer**: O seeder deve desabilitar o observer (`Task::withoutEvents()`) ao criar tasks para evitar que o observer crie registros automaticos que conflitem com o historico retroativo manual.

6. **Performance do Dashboard**: Usar queries agregadas (subqueries, `selectRaw`) em vez de carregar todos os models. Limitar a tasks dos ultimos 30 dias.

7. **Graceful degradation**: Tasks sem historico (criadas antes do observer) devem funcionar sem erros — accessors retornam valores vazios/zero, barra nao renderiza, metricas ignoram essas tasks.

8. **Tipo "frontend" para MCP**: O GetTaskTool e classificado como "frontend" pois a mudanca e apenas na formatacao do response (adicionar campos), sem logica de negocio nova.
