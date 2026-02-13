---
epic: "Epic 3: Kanban Board"
description: "Kanban Board visual com 4 colunas (Backlog, Todo, Doing, Done), drag-and-drop via wire:sort, filtros por projeto/prioridade/overdue, lazy loading, cards ricos com badges, e Task Modal reativo com editor rich text e time entries editaveis inline."
created_at: "2026-02-13"
total_stories: 8
complexity_summary:
  small: 2
  medium: 4
  large: 2
type_summary:
  core: 1
  frontend: 5
  both: 2
---

# User Stories: Epic 3 -- Kanban Board

## Visao Geral

Este epic implementa o core visual do SoloBoard: um Kanban Board com 4 colunas de status (Backlog, Todo, Doing, Done) e um Task Modal reativo para edicao completa de tasks. O board suporta drag-and-drop entre colunas via `wire:sort:group`, lazy loading de 20 tasks por coluna, filtros por projeto/prioridade/overdue, e cards ricos com badges de prioridade, estimativa, overdue e timer pulsing. O Task Modal permite edicao completa da task (incluindo rich text com `<flux:editor>`) sem fechar ao salvar, e time entries editaveis inline.

**Pre-requisitos:** Todos os Models, Enums, Factories e Migrations do Epic 1/2 ja existem e estao funcionais. Os scopes `byStatus`, `overdue`, `doneThisWeek`, `unassigned` ja existem no Model Task. O metodo `markAsDone()` ja para timers e preenche `completed_at`.

## Ordem de Execucao

1. **US-001** -- Rota /kanban e pagina SFC basica com 4 colunas (core)
2. **US-002** -- Queries por coluna com lazy loading e contagem (core)
3. **US-003** -- Renderizacao dos cards com badges e informacoes visuais (frontend)
4. **US-004** -- Filtros: projeto, prioridade e toggle overdue (frontend)
5. **US-005** -- Drag-and-drop entre colunas com wire:sort:group (both)
6. **US-006** -- Task Modal: estrutura basica e edicao de campos (both)
7. **US-007** -- Task Modal: time entries editaveis inline (frontend)
8. **US-008** -- Task Modal: deletar task com confirmacao (frontend)

---

## Lista de User Stories

### US-001: Rota /kanban e pagina SFC basica com 4 colunas

**Como** usuario
**Quero** acessar a pagina /kanban e ver 4 colunas de status (Backlog, Todo, Doing, Done)
**Para** ter uma visao geral do fluxo de trabalho das minhas tasks

**Criterios de Aceitacao:**

- [ ] Rota `GET /kanban` existe com middleware `auth` e nome `kanban`
- [ ] Arquivo `resources/views/pages/kanban.blade.php` existe como SFC (prefixo nao necessario no nome do arquivo pois Folio nao e usado; o arquivo segue o padrao SFC com `new class extends Component`)
- [ ] A pagina renderiza 4 colunas lado a lado: Backlog, Todo, Doing, Done
- [ ] Cada coluna exibe o label do status (via `TaskStatus::label()`) e o icone correspondente (via `TaskStatus::icon()`)
- [ ] Cada coluna exibe a contagem de tasks entre parenteses (ex: "Backlog (12)")
- [ ] A coluna Done filtra apenas tasks completadas na semana atual (segunda a domingo corrente) usando scope `doneThisWeek`
- [ ] Layout responsivo: colunas em scroll horizontal no mobile, grid de 4 colunas no desktop
- [ ] Link "Kanban" na sidebar aponta para `route('kanban')` em vez de `route('dashboard')`
- [ ] Given usuario nao autenticado, When acessa `/kanban`, Then e redirecionado para login
- [ ] Teste Feature: rota requer auth, pagina renderiza com 4 colunas, coluna Done filtra por semana

**Complexidade:** M
**Estimativa:** 3h
**Dependencias:** Nenhuma
**Tipo:** core

**Arquivos afetados:**
- `resources/views/pages/kanban.blade.php` (criar -- SFC)
- `routes/web.php` (editar -- adicionar rota /kanban)
- `resources/views/layouts/app/sidebar.blade.php` (editar -- corrigir href do Kanban)
- `tests/Feature/KanbanTest.php` (criar)

---

### US-002: Queries por coluna com lazy loading e botao "carregar mais"

**Como** usuario
**Quero** que cada coluna carregue apenas 20 tasks inicialmente com opcao de carregar mais
**Para** ter performance rapida mesmo com muitas tasks no board

**Criterios de Aceitacao:**

- [ ] Cada coluna carrega no maximo 20 tasks inicialmente, ordenadas por `sort_order` asc, depois `created_at` desc
- [ ] Se existem mais de 20 tasks na coluna, um botao "Carregar mais" aparece no final da coluna
- [ ] Given coluna Backlog tem 25 tasks, When pagina carrega, Then exibe 20 tasks e botao "Carregar mais"
- [ ] Given usuario clica "Carregar mais", Then as proximas 20 tasks sao carregadas e adicionadas a lista
- [ ] Given coluna tem exatamente 20 tasks ou menos, Then botao "Carregar mais" NAO aparece
- [ ] Tasks sao carregadas com eager loading de `project` e `timeEntries` (running) para evitar N+1
- [ ] A propriedade de limite por coluna e gerenciada via array (ex: `$limits = ['backlog' => 20, 'todo' => 20, ...]`)
- [ ] Metodo `loadMore(string $status)` incrementa o limite da coluna em 20
- [ ] Teste Feature: lazy loading carrega 20, loadMore carrega mais 20, eager loading sem N+1

**Complexidade:** M
**Estimativa:** 3h
**Dependencias:** US-001
**Tipo:** core

**Arquivos afetados:**
- `resources/views/pages/kanban.blade.php` (editar -- adicionar lazy loading)
- `tests/Feature/KanbanTest.php` (editar -- adicionar testes de lazy loading)

---

### US-003: Cards com badges, projeto info e timer pulsing

**Como** usuario
**Quero** ver cards ricos nas colunas do kanban com badges de prioridade, estimativa, overdue, info do projeto e indicador de timer
**Para** ter contexto visual rapido sobre cada task sem precisar abrir o modal

**Criterios de Aceitacao:**

- [ ] Cada card exibe o titulo da task
- [ ] Card exibe badge de prioridade com cor e label do enum `TaskPriority` (ex: badge vermelho "Urgente")
- [ ] Card exibe badge de estimativa quando `estimated_minutes` esta preenchido (ex: "~30min", "~2h")
- [ ] Card exibe badge "Atrasada" em vermelho quando `isOverdue()` retorna true
- [ ] Card exibe info do projeto quando task tem projeto: borda esquerda com cor do projeto, emoji + nome do projeto
- [ ] Card exibe icone pulsing verde quando a task tem um timer rodando (`timeEntries.running` existe)
- [ ] Click no card dispara evento `dispatch('open-task-modal', { taskId: X })`
- [ ] Cards de tasks sem projeto aparecem em secao "Sem projeto" separada visualmente abaixo das tasks com projeto, dentro da mesma coluna
- [ ] Secao "Sem projeto" so aparece se existem tasks sem projeto naquela coluna
- [ ] Teste Feature: card renderiza badges corretos, secao sem projeto aparece/esconde, click dispara evento

**Complexidade:** G
**Estimativa:** 5h
**Dependencias:** US-002
**Tipo:** frontend

**Arquivos afetados:**
- `resources/views/pages/kanban.blade.php` (editar -- adicionar cards e badges)
- `tests/Feature/KanbanTest.php` (editar -- adicionar testes de cards)

---

### US-004: Filtros por projeto, prioridade e toggle overdue

**Como** usuario
**Quero** filtrar as tasks do kanban por projeto, prioridade e toggle de overdue
**Para** focar nas tasks relevantes e encontrar rapidamente tasks atrasadas

**Criterios de Aceitacao:**

- [ ] Barra de filtros acima das colunas com 3 controles: select projeto, select prioridade, toggle overdue
- [ ] `<flux:select>` de projeto lista todos os projetos ativos + opcao "Todos" (valor vazio)
- [ ] Given usuario seleciona um projeto, Then todas as colunas mostram apenas tasks daquele projeto
- [ ] `<flux:select>` de prioridade lista todas as prioridades do enum + opcao "Todas" (valor vazio)
- [ ] Given usuario seleciona prioridade "Urgente", Then todas as colunas mostram apenas tasks urgentes
- [ ] Toggle overdue (checkbox ou switch) filtra apenas tasks com `isOverdue() = true`
- [ ] Given URL contem `?overdue=1`, Then o toggle overdue e ativado automaticamente ao carregar a pagina
- [ ] Filtros sao combinaveis: projeto + prioridade + overdue funcionam juntos
- [ ] Filtros atualizam a URL via query string para permitir compartilhamento/bookmark
- [ ] Contagem de tasks por coluna atualiza ao aplicar filtros
- [ ] Teste Feature: filtro por projeto, filtro por prioridade, toggle overdue, filtros combinados, query param ?overdue=1

**Complexidade:** M
**Estimativa:** 4h
**Dependencias:** US-002
**Tipo:** frontend

**Arquivos afetados:**
- `resources/views/pages/kanban.blade.php` (editar -- adicionar filtros)
- `tests/Feature/KanbanTest.php` (editar -- adicionar testes de filtros)

---

### US-005: Drag-and-drop entre colunas com wire:sort:group

**Como** usuario
**Quero** arrastar tasks entre colunas do kanban para mudar seu status
**Para** gerenciar o fluxo de trabalho de forma visual e intuitiva

**Criterios de Aceitacao:**

- [ ] Cada coluna usa `wire:sort:group="kanban"` com `wire:sort:group-id` sendo o valor do status
- [ ] Cada card usa `wire:sort:item` com o ID da task
- [ ] Given usuario arrasta task de Backlog para Todo, Then `task.status` muda para `todo` e `sort_order` e recalculado
- [ ] Given usuario arrasta task de Doing para Done, Then `task.markAsDone()` e chamado (para timer + completed_at + status)
- [ ] Given usuario arrasta task para Done e task esta no DailyPlan de hoje, Then `daily_plan_task.completed_at` e preenchido
- [ ] Given usuario arrasta task de Done para outra coluna, Then `completed_at` e limpo e status atualizado
- [ ] `sort_order` e recalculado para a coluna de destino baseado na posicao do drop
- [ ] Metodo `handleSort($taskId, $position, $statusValue)` processa o drag-and-drop
- [ ] Drag-and-drop funciona tanto para tasks com projeto quanto para tasks sem projeto
- [ ] Teste Feature: mover task entre colunas atualiza status, mover para Done chama markAsDone, sort_order recalculado

**Complexidade:** G
**Estimativa:** 5h
**Dependencias:** US-003
**Tipo:** both

**Arquivos afetados:**
- `resources/views/pages/kanban.blade.php` (editar -- adicionar wire:sort)
- `tests/Feature/KanbanTest.php` (editar -- adicionar testes de drag-and-drop)

---

### US-006: Task Modal -- estrutura basica e edicao de campos

**Como** usuario
**Quero** abrir um modal ao clicar em um card do kanban e editar todos os campos da task
**Para** gerenciar detalhes da task sem sair do board

**Criterios de Aceitacao:**

- [ ] Componente SFC `resources/views/components/task-modal.blade.php` (prefixo nao necessario no nome do arquivo)
- [ ] Modal abre ao receber evento `open-task-modal` com `taskId` via `#[On('open-task-modal')]`
- [ ] Modal exibe campos editaveis com componentes Flux:
  - [ ] `<flux:input>` para titulo
  - [ ] `<flux:editor>` para descricao (rich text markdown)
  - [ ] `<flux:select>` para projeto (lista projetos ativos + "Sem projeto")
  - [ ] `<flux:select>` para prioridade (enum TaskPriority)
  - [ ] `<flux:select>` para status (enum TaskStatus)
  - [ ] `<flux:date-picker>` para prazo (due_date)
  - [ ] `<flux:input type="number">` para estimativa em minutos
- [ ] Botao "Salvar" persiste alteracoes no banco SEM fechar o modal
- [ ] Given usuario edita titulo e clica Salvar, Then titulo e atualizado no banco e toast de confirmacao aparece
- [ ] Given usuario muda status para Done via select, Then `markAsDone()` e chamado
- [ ] Modal fecha com Esc ou clicando fora
- [ ] Apos salvar, o kanban board atualiza para refletir mudancas (dispatch evento `task-updated`)
- [ ] Validacao: titulo obrigatorio, estimativa >= 0
- [ ] Teste Feature: abrir modal via evento, editar campos, salvar persiste sem fechar, validacao

**Complexidade:** G
**Estimativa:** 5h
**Dependencias:** US-003
**Tipo:** both

**Arquivos afetados:**
- `resources/views/components/task-modal.blade.php` (criar -- SFC)
- `resources/views/pages/kanban.blade.php` (editar -- incluir componente e listener)
- `tests/Feature/TaskModalTest.php` (criar)

---

### US-007: Task Modal -- time entries editaveis inline

**Como** usuario
**Quero** ver e editar as time entries de uma task diretamente no modal
**Para** corrigir horarios e adicionar notas sem precisar de outra tela

**Criterios de Aceitacao:**

- [ ] Secao "Registros de Tempo" no modal lista todas as time entries da task ordenadas por `started_at` desc
- [ ] Cada entry exibe: started_at (editavel), stopped_at (editavel), duracao calculada (readonly), notes (editavel)
- [ ] Campos de horario usam `<flux:input type="datetime-local">` para edicao
- [ ] Campo notes usa `<flux:input>` com placeholder "Notas..."
- [ ] Given usuario edita started_at de uma entry e clica salvar, Then o horario e atualizado no banco
- [ ] Given usuario edita notes de uma entry, Then as notas sao atualizadas no banco
- [ ] Botao de deletar entry individual com confirmacao inline (nao modal aninhado)
- [ ] Given usuario deleta uma entry, Then ela e removida do banco e da lista
- [ ] Entry com `stopped_at = null` (running) exibe badge "Em andamento" e duracao atualiza em tempo real
- [ ] Validacao: started_at obrigatorio, stopped_at deve ser posterior a started_at (quando preenchido)
- [ ] Teste Feature: listar entries, editar entry, deletar entry, validacao de horarios

**Complexidade:** M
**Estimativa:** 4h
**Dependencias:** US-006
**Tipo:** frontend

**Arquivos afetados:**
- `resources/views/components/task-modal.blade.php` (editar -- adicionar secao time entries)
- `tests/Feature/TaskModalTest.php` (editar -- adicionar testes de time entries)

---

### US-008: Task Modal -- deletar task com confirmacao

**Como** usuario
**Quero** deletar uma task diretamente do modal com confirmacao
**Para** remover tasks que nao sao mais necessarias

**Criterios de Aceitacao:**

- [ ] Footer do modal exibe botao "Excluir" com variante danger
- [ ] Given usuario clica "Excluir", Then um modal de confirmacao aninhado aparece com texto "Tem certeza que deseja excluir [titulo]? Esta acao nao pode ser desfeita."
- [ ] Given usuario confirma exclusao, Then a task e deletada do banco, modal fecha, e toast de confirmacao aparece
- [ ] Given usuario cancela exclusao, Then o modal de confirmacao fecha e o modal principal permanece aberto
- [ ] Apos deletar, o kanban board atualiza para remover o card (dispatch evento `task-deleted`)
- [ ] Deletar task tambem remove time entries associadas (cascade no banco)
- [ ] Teste Feature: deletar task com confirmacao, task removida do banco, time entries removidas em cascade

**Complexidade:** P
**Estimativa:** 2h
**Dependencias:** US-006
**Tipo:** frontend

**Arquivos afetados:**
- `resources/views/components/task-modal.blade.php` (editar -- adicionar botao e modal de confirmacao)
- `tests/Feature/TaskModalTest.php` (editar -- adicionar testes de exclusao)

---

## Resumo de Complexidade

| Complexidade | Quantidade | User Stories |
|-------------|-----------|--------------|
| P (Pequeno) | 2 | US-002, US-008 |
| M (Medio)   | 4 | US-001, US-004, US-005, US-007 |
| G (Grande)  | 2 | US-003, US-006 |

**Estimativa total:** ~31h

## Grafo de Dependencias

```
US-001 (Rota + 4 colunas)
  |
  v
US-002 (Lazy loading)
  |
  +---> US-003 (Cards + badges) --+--> US-005 (Drag-and-drop wire:sort)
  |                                |
  +---> US-004 (Filtros)           +--> US-006 (Task Modal basico) --+--> US-007 (Time entries inline)
                                                                      |
                                                                      +--> US-008 (Deletar task)
```

## Notas Tecnicas

1. **SFC Pattern**: Todos os componentes seguem o padrao Single-File Component com `new class extends Component {}` no topo do Blade. Nao criar arquivos PHP separados em `app/Livewire/`.

2. **wire:sort:group**: Livewire 4 suporta nativamente `wire:sort:group` para drag entre grupos. O handler recebe `($id, $position, $groupId)` onde `$groupId` e o valor do status da coluna destino.

3. **NAO existe `<flux:kanban>`**: O board e construido com HTML/Tailwind puro + `wire:sort`. Layout com `flex` horizontal e `overflow-x-auto` para scroll no mobile.

4. **Coluna Done**: Usa scope `doneThisWeek()` que ja existe no Model Task. Filtra `completed_at` entre segunda e domingo da semana corrente.

5. **markAsDone()**: O metodo ja existe no Model Task e faz: para timers running, atualiza status para Done, preenche completed_at. A US-005 adiciona a logica de sync com DailyPlan.

6. **Flux Editor**: Componente `<flux:editor wire:model="description">` do Flux Pro. JS carregado on-the-fly (nao incluso no bundle core). Suporta markdown syntax inline.

7. **Eventos entre componentes**: O Task Modal dispara `task-updated` e `task-deleted` que o Kanban Board escuta via `#[On]` para atualizar as colunas.

8. **Query params**: O filtro overdue suporta `?overdue=1` na URL. Usar `Livewire\Attributes\Url` para sincronizar filtros com query string.

9. **Secao "Sem projeto"**: Dentro de cada coluna, tasks com projeto aparecem primeiro (agrupadas), seguidas de um separador visual e tasks sem projeto. Ambas as secoes participam do mesmo `wire:sort:group`.

10. **Locale PT-BR**: Labels de Enums, toasts, empty states em portugues. Codigo (variaveis, metodos, classes) em ingles.
