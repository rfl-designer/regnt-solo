# Documento de Contexto: Epic 3 — Kanban Board

## 1. Resumo Executivo

Implementar o Kanban Board e o Task Modal reativo do SoloBoard, composto por:

1. **Kanban Board (Task 3.1)** — Página SFC com 4 colunas (Backlog, Todo, Doing, Done) com drag-and-drop via `wire:sort` + `wire:sort:group`, lazy loading (20 tasks/coluna), filtros por projeto/prioridade/overdue, seção "Sem projeto" separada, e lógica de negócio ao mover para Done (parar timer, preencher completed_at, marcar DailyPlan).
2. **Task Modal (Task 3.2)** — Componente SFC modal reativo que NÃO fecha ao salvar, com campos Flux (input, editor, select, date-picker), seção de TimeEntries editável inline, e confirmação de exclusão aninhada.

---

## 2. Requisitos

### Funcionais

#### Task 3.1 — Kanban Board SFC

- [ ] 4 colunas: Backlog, Todo, Doing, Done
- [ ] Coluna Done mostra apenas tasks da semana atual (segunda a domingo corrente) via scope `doneThisWeek`
- [ ] Lazy loading: 20 tasks por coluna, botão "carregar mais" (incrementa limite)
- [ ] Filtros:
  - `<flux:select>` projeto (com opção "Todos") — filtra por `project_id`
  - `<flux:select>` prioridade (com opção "Todas") — filtra por `priority`
  - Toggle overdue (mostrar só atrasadas) — suportar query param `?overdue=1`
- [ ] Cards exibem:
  - Badge prioridade (cor do enum `TaskPriority::color()`)
  - Badge estimativa (ex: "~30min") se `estimated_minutes` preenchido
  - Badge overdue (vermelho) se `isOverdue()` retorna true
  - Projeto: borda com cor do projeto + emoji + nome
  - Timer rodando: ícone pulsing verde se `isRunning()` retorna true
  - Click no card → `$dispatch('open-task-modal', { taskId: X })`
- [ ] Seção "Sem projeto": abaixo das tasks com projeto em cada coluna, separada visualmente, só aparece se existem tasks sem `project_id`
- [ ] Mover task para Done:
  - Para timer se rodando (via `markAsDone()` que já faz isso)
  - Preenche `completed_at` (via `markAsDone()`)
  - Marca done no DailyPlan de hoje (se task está no plano do dia)
- [ ] `wire:sort` + `wire:sort:group` para drag entre colunas (muda status + recalcula `sort_order`)
- [ ] Rota: `/kanban` com middleware `auth`, nome `kanban`
- [ ] Atualizar sidebar: href do item Kanban de `route('dashboard')` para `route('kanban')`

#### Task 3.2 — Task Modal SFC

- [ ] Modal NÃO fecha ao salvar — fica aberta e dados atualizam reativamente
- [ ] Campos Flux:
  - `<flux:input>` título
  - `<flux:editor>` descrição (rich text ProseMirror/Tiptap)
  - `<flux:select>` projeto (com opção "Sem projeto")
  - `<flux:select>` prioridade
  - `<flux:select>` status
  - `<flux:date-picker>` prazo (due_date)
  - `<flux:input type="number">` estimativa em minutos
- [ ] Seção TimeEntries editável:
  - Lista de entries com started_at, stopped_at, duration (calculado), notes
  - Campos editáveis inline para ajustar horários e notas
  - Botão deletar entry individual
- [ ] Footer:
  - Botão "Salvar" (persiste sem fechar modal)
  - Botão "Deletar" (com modal de confirmação aninhado)
  - Toast de confirmação ao salvar/deletar
- [ ] Listener: `'open-task-modal'` com `taskId`
- [ ] Fecha com Esc (comportamento padrão do `<flux:modal>`)

### Nao-Funcionais

- Interface em PT-BR (labels, botoes, mensagens, toasts, empty states)
- Codigo em ingles (variaveis, classes, metodos)
- Dark mode only (sem light mode)
- SFC (Single-File Components) — sem arquivos PHP separados em `app/Livewire/`
- Testes Feature com Pest para toda logica de negocio
- Performance: eager loading de `project` e `timeEntries` (running) para evitar N+1
- Acessibilidade: `wire:sort:handle` para drag handles, `wire:sort:ignore` em botoes interativos

---

## 3. Analise do Codebase

### Estrutura Relevante

```
app/
  Models/
    Task.php              # Scopes: inbox, active, byStatus, overdue, unassigned, doneThisWeek
                          # Metodos: isOverdue(), isRunning(), markAsDone()
                          # Relations: project(), timeEntries(), dailyPlans()
    Project.php           # Scopes: active, paused, archived, ordered
                          # Relations: tasks()
    TimeEntry.php         # Scopes: running, forDate, forWeek
                          # Accessor: duration_minutes
                          # Static: stopAllRunning()
    DailyPlan.php         # Static: getOrCreateForDate()
                          # Relations: tasks() (pivot: sort_order, completed_at)
                          # Metodos: completionRate(), incompleteTasks()
    User.php

  Enums/
    TaskStatus.php        # Inbox, Backlog, Todo, Doing, Done (com label, color, icon)
    TaskPriority.php      # Urgent, High, Medium, Low (com label, color, icon)
    ProjectStatus.php     # Active, Paused, Archived
    ProjectPriority.php   # High, Medium, Low

resources/views/
  pages/
    ⚡dashboard.blade.php  # Placeholder
    ⚡inbox.blade.php      # Padrao SFC existente (referencia para convencoes)
  components/
    ⚡task-quick-add.blade.php  # Padrao SFC componente (referencia)
    ⚡inbox-badge.blade.php     # Padrao SFC componente reativo com eventos
  layouts/
    app.blade.php              # Wrapper para sidebar
    app/sidebar.blade.php      # Sidebar com link Kanban (placeholder → dashboard)

routes/
  web.php                      # Rotas existentes: dashboard, inbox

tests/Feature/
  TaskTest.php                 # Padrao de testes de model
  InboxTest.php                # Padrao de testes de pagina Livewire SFC
  TaskQuickAddTest.php         # Padrao de testes de componente SFC
  TimeEntryTest.php
  DailyPlanTest.php

database/factories/
  TaskFactory.php              # States: done, doing, todo, backlog, overdue, urgent, withDueDate, withEstimate
  TimeEntryFactory.php         # States: running
  ProjectFactory.php           # States: paused, archived, highPriority, lowPriority
  DailyPlanFactory.php         # States: today
```

### Padroes Identificados

1. **SFC Format**: `<?php ... new class extends Component { ... } ?> <div>...</div>` — PHP no topo, Blade embaixo
2. **Computed Properties**: `#[Computed]` para queries reativas com `unset($this->prop)` para invalidar cache
3. **Eventos Livewire**: `$this->dispatch('event-name')` para comunicacao entre componentes
4. **Listeners**: `#[On('event-name')]` para escutar eventos
5. **Flux Toast**: `Flux::toast(variant: 'success', heading: '...', text: '...')` para feedback
6. **Flux Modal**: `wire:model.self` para controle de estado, `<flux:modal.close>` para fechar
7. **Rotas**: `Route::livewire('path', 'pages::name')->middleware(['auth'])->name('name')`
8. **Testes**: `beforeEach` com `actingAs`, `Livewire::test('component-name')`, assertions com `assertSee`, `assertSet`, `assertDispatched`
9. **Factory usage em testes**: `Task::factory()->backlog()->create()`, `Task::factory()->overdue()->create()`
10. **Dark mode**: Classe `dark` fixa no `<html>`, cores `zinc-700/800/900`
11. **PT-BR**: Labels e mensagens em portugues, codigo em ingles

### Codigo Existente Reutilizavel

| Artefato | Uso no Kanban |
|----------|---------------|
| `Task::scopeByStatus()` | Filtrar tasks por coluna |
| `Task::scopeDoneThisWeek()` | Coluna Done |
| `Task::scopeOverdue()` | Filtro overdue |
| `Task::scopeUnassigned()` | Secao "Sem projeto" |
| `Task::isOverdue()` | Badge overdue no card |
| `Task::isRunning()` | Icone pulsing verde |
| `Task::markAsDone()` | Mover para Done (para timer + completed_at) |
| `TimeEntry::scopeRunning()` | Detectar timer rodando |
| `DailyPlan::getOrCreateForDate()` | Marcar done no plano do dia |
| `TaskPriority::color()` | Cor do badge prioridade |
| `TaskPriority::label()` | Label do badge prioridade |
| `TaskStatus::label()` | Header das colunas |
| `TaskStatus::color()` | Cor visual das colunas |
| `TaskStatus::icon()` | Icone das colunas |
| `Project::scopeActive()` | Filtro de projetos |

---

## 4. Dependencias

### Externas (ja instaladas)

| Pacote | Versao | Uso |
|--------|--------|-----|
| `livewire/livewire` | 4.1.4 | SFC, `wire:sort`, `wire:sort:group`, `wire:model`, eventos |
| `livewire/flux-pro` | 2.12.0 | Modal, Input, Select, Editor, Date-picker, Badge, Button, Toast |
| `tailwindcss` | 4.1.18 | Estilizacao do board, cards, colunas |
| `pestphp/pest` | 4.3.2 | Testes Feature |

### Internas (ja existem)

| Artefato | Caminho | Necessario para |
|----------|---------|-----------------|
| Model Task | `app/Models/Task.php` | Queries, scopes, metodos de negocio |
| Model Project | `app/Models/Project.php` | Filtro por projeto, dados do card |
| Model TimeEntry | `app/Models/TimeEntry.php` | Secao TimeEntries no modal, timer running |
| Model DailyPlan | `app/Models/DailyPlan.php` | Marcar done no plano do dia |
| Enum TaskStatus | `app/Enums/TaskStatus.php` | Colunas do kanban, selects |
| Enum TaskPriority | `app/Enums/TaskPriority.php` | Badges, filtros |
| Factory TaskFactory | `database/factories/TaskFactory.php` | Testes |
| Factory TimeEntryFactory | `database/factories/TimeEntryFactory.php` | Testes |
| Factory ProjectFactory | `database/factories/ProjectFactory.php` | Testes |
| Factory DailyPlanFactory | `database/factories/DailyPlanFactory.php` | Testes |

### Modulos Afetados

- `routes/web.php` — Nova rota `/kanban`
- `resources/views/layouts/app/sidebar.blade.php` — Corrigir href do Kanban
- `resources/views/layouts/app.blade.php` — Incluir `<livewire:task-modal />` (global, como task-quick-add)

---

## 5. Riscos e Mitigacoes

| # | Risco | Probabilidade | Impacto | Mitigacao |
|---|-------|---------------|---------|-----------|
| 1 | **`<flux:kanban>` NAO existe** — O epic original menciona `<flux:kanban>` nativo, mas esse componente nao existe no Flux UI Pro 2.x | **Confirmado** | **Critico** | Construir o kanban com HTML/Tailwind + `wire:sort` + `wire:sort:group`. Usar `<div>` com grid/flex layout para colunas. Nao depender de componente Flux inexistente. |
| 2 | **Complexidade do drag-and-drop entre colunas** — `wire:sort:group` precisa de handler que recebe `(id, position, groupId)` e deve atualizar status + sort_order atomicamente | Media | Alto | Seguir documentacao oficial do Livewire 4 `wire:sort`. O `groupId` sera o valor do `TaskStatus` (backlog, todo, doing, done). Usar transacao DB para atualizar status e reordenar. |
| 3 | **Performance com muitas tasks** — Carregar tasks de 4 colunas + eager loading pode ser pesado | Media | Medio | Lazy loading de 20 tasks por coluna. Eager load apenas `project` e `timeEntries` (running). Usar `#[Computed]` com cache. |
| 4 | **`flux:editor` carregamento assincrono** — O JS do editor e carregado on-the-fly (nao no bundle principal), pode causar flash/delay | Baixa | Baixo | Aceitar o comportamento padrao do Flux. O editor so aparece no modal, nao na pagina principal. |
| 5 | **Modal nao fechar ao salvar** — Comportamento nao-padrao que precisa de controle manual de estado | Baixa | Baixo | Usar `wire:model.self` no modal com propriedade booleana. O metodo `save()` atualiza dados mas nao altera a propriedade do modal. |
| 6 | **Confirmacao aninhada (modal dentro de modal)** — Deletar task precisa de modal de confirmacao dentro do task modal | Media | Medio | Usar segunda propriedade booleana `showDeleteConfirmation` com `<flux:modal wire:model.self="showDeleteConfirmation">` aninhado. Flux suporta modais aninhados. |
| 7 | **Reordenacao de sort_order ao mover entre colunas** — Precisa recalcular sort_order de todas as tasks na coluna destino | Media | Medio | No handler `handleSort($id, $position, $groupId)`: atualizar status da task para o novo grupo, e reordenar tasks da coluna destino. Usar query com `CASE WHEN` ou loop simples (poucas tasks por coluna com lazy loading). |
| 8 | **Sincronizacao DailyPlan ao mover para Done** — Precisa verificar se task esta no plano do dia e marcar completed_at no pivot | Baixa | Baixo | Apos `markAsDone()`, buscar DailyPlan de hoje e atualizar pivot se existir. Logica simples e isolada. |
| 9 | **Secao "Sem projeto" duplica logica de colunas** — Cada coluna precisa separar tasks com e sem projeto | Media | Medio | Na query, carregar todas as tasks da coluna e separar no Blade com `@if($task->project_id)`. Ou usar duas queries separadas por coluna (com e sem projeto). Preferir separacao no Blade para simplicidade. |
| 10 | **`flux:date-picker` pode nao existir** — Verificar se existe no Flux Pro 2.x | Media | Medio | Se nao existir, usar `<flux:input type="date">` como fallback. Buscar na documentacao do Flux. |

---

## 6. Decisoes Tecnicas

### 6.1 Kanban Board — Construcao sem `<flux:kanban>`

**Decisao**: Construir o board com HTML semantico + Tailwind CSS + `wire:sort` nativo do Livewire 4.

**Estrutura do layout**:
```html
<div class="flex gap-4 overflow-x-auto h-full">
    @foreach ($columns as $status => $column)
        <div class="flex-shrink-0 w-80 flex flex-col">
            <!-- Header da coluna -->
            <div>{{ $status->label() }} ({{ $column['count'] }})</div>

            <!-- Tasks com projeto -->
            <ul wire:sort="handleSort"
                wire:sort:group="kanban"
                wire:sort:group-id="{{ $status->value }}">
                @foreach ($column['tasks'] as $task)
                    <li wire:key="{{ $task->id }}"
                        wire:sort:item="{{ $task->id }}">
                        <!-- Card content -->
                    </li>
                @endforeach
            </ul>

            <!-- Secao "Sem projeto" -->
            @if ($column['unassigned']->isNotEmpty())
                <div class="border-t border-dashed">
                    <ul wire:sort="handleSort"
                        wire:sort:group="kanban"
                        wire:sort:group-id="{{ $status->value }}">
                        @foreach ($column['unassigned'] as $task)
                            ...
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Botao carregar mais -->
        </div>
    @endforeach
</div>
```

**Nota sobre wire:sort:group**: Todas as listas `<ul>` com o mesmo `wire:sort:group="kanban"` permitem drag entre elas. O `wire:sort:group-id` identifica a coluna destino. O handler recebe `($id, $position, $groupId)` onde `$groupId` e o valor do status (ex: "doing").

### 6.2 Handler de Sort

```php
public function handleSort(int|string $id, int $position, string $groupId): void
{
    $task = Task::findOrFail($id);
    $newStatus = TaskStatus::from($groupId);

    // Se movendo para Done, usar markAsDone()
    if ($newStatus === TaskStatus::Done) {
        $task->markAsDone();
        $this->syncDailyPlanDone($task);
    } else {
        $task->update(['status' => $newStatus]);
    }

    // Recalcular sort_order na coluna destino
    $this->reorderColumn($newStatus, $id, $position);
}
```

### 6.3 Lazy Loading por Coluna

Cada coluna tera uma propriedade de limite:
```php
public array $limits = [
    'backlog' => 20,
    'todo' => 20,
    'doing' => 20,
    'done' => 20,
];

public function loadMore(string $status): void
{
    $this->limits[$status] += 20;
}
```

### 6.4 Task Modal — Controle de Estado

```php
public bool $showTaskModal = false;
public bool $showDeleteConfirmation = false;
public ?int $editingTaskId = null;

// Propriedades do formulario
public string $title = '';
public string $description = '';
// ...

#[On('open-task-modal')]
public function openTaskModal(int $taskId): void
{
    $this->editingTaskId = $taskId;
    $this->loadTaskData();
    $this->showTaskModal = true;
}

public function saveTask(): void
{
    // Valida e salva
    // NAO altera $showTaskModal — modal permanece aberta
    Flux::toast(variant: 'success', heading: 'Task salva');
}
```

### 6.5 TimeEntries Editavel

Usar array de entries com binding:
```php
/** @var array<int, array{id: int, started_at: string, stopped_at: ?string, notes: ?string}> */
public array $timeEntries = [];

public function updateTimeEntry(int $index): void
{
    // Valida e atualiza entry no DB
}

public function deleteTimeEntry(int $index): void
{
    // Remove entry do DB e do array
}
```

### 6.6 Secao "Sem projeto"

**Decisao**: Separar tasks com e sem projeto no Blade, nao na query. Carregar todas as tasks da coluna e usar `partition()` ou `groupBy()` no Collection.

```php
// No computed
$tasks = Task::byStatus($status)->with('project')->limit($limit)->get();
$withProject = $tasks->whereNotNull('project_id');
$withoutProject = $tasks->whereNull('project_id');
```

### 6.7 Filtro Overdue via Query Param

```php
use Livewire\Attributes\Url;

#[Url]
public bool $overdue = false;
```

Isso sincroniza automaticamente com `?overdue=1` na URL.

---

## 7. Escopo

### Incluido

- Pagina SFC `resources/views/pages/⚡kanban.blade.php`
- Componente SFC `resources/views/components/⚡task-modal.blade.php`
- Rota `/kanban` em `routes/web.php`
- Atualizacao da sidebar (href do Kanban)
- Inclusao do task-modal no layout global
- Testes Feature: `tests/Feature/KanbanTest.php`
- Testes Feature: `tests/Feature/TaskModalTest.php`

### Excluido

- Timer global na sidebar/header (Epic 5)
- Criacao de tasks pelo kanban (usa TaskQuickAdd existente)
- Drag de tasks da Inbox para o kanban (Inbox e pagina separada)
- Edicao de projetos (Epic 7)
- Relatorios de tempo (Epic 8)
- Command Palette `Cmd+K` (Epic 9)
- Responsividade mobile do board (pode ser adicionada no Epic 9 — Polish)

---

## 8. Arquivos a Criar/Modificar

### Criar

| Arquivo | Tipo | Descricao |
|---------|------|-----------|
| `resources/views/pages/⚡kanban.blade.php` | SFC Page | Kanban board com 4 colunas |
| `resources/views/components/⚡task-modal.blade.php` | SFC Component | Modal reativo de edicao de task |
| `tests/Feature/KanbanTest.php` | Teste | Testes do kanban board |
| `tests/Feature/TaskModalTest.php` | Teste | Testes do task modal |

### Modificar

| Arquivo | Alteracao |
|---------|-----------|
| `routes/web.php` | Adicionar rota `/kanban` |
| `resources/views/layouts/app/sidebar.blade.php` | Corrigir href do item Kanban para `route('kanban')` |
| `resources/views/layouts/app.blade.php` | Incluir `<livewire:task-modal />` |

---

## 9. Proximos Passos

1. **Aprovar este documento de contexto**
2. **Encaminhar para task-breakdown** para criacao das User Stories detalhadas
3. **Implementar Task 3.1** — Kanban Board SFC (pagina + rota + sidebar)
4. **Implementar Task 3.2** — Task Modal SFC (componente + inclusao no layout)
5. **Rodar testes** e validar tudo funciona
6. **Rodar Pint** para formatacao (`vendor/bin/pint --dirty --format agent`)
