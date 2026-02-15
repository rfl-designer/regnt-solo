# Documento de Contexto: Epic 11 — Status Time Tracking Automatico

## 1. Resumo Executivo

Implementar rastreamento automatico de quanto tempo cada task passa em cada status/coluna (Inbox, Backlog, Todo, Doing, Done). O sistema registra mudancas de status via Observer e calcula duracao a partir dos timestamps — sem nenhuma acao do usuario. Inspirado no Linear.

Composto por:

1. **Task 11.1 — Model TaskStatusChange + Observer**: Criar tabela `task_status_changes`, model `TaskStatusChange`, factory, observer `TaskObserver` para registrar automaticamente cada mudanca de status, e accessors no `Task` model para calcular tempo em cada status.
2. **Task 11.2 — Visualizacao no Task Modal + Dashboard + MCP**: Barra horizontal segmentada no task-modal mostrando tempo por status, metricas de tempo medio no dashboard, e timeline de status no `GetTaskTool`.

---

## 2. Requisitos

### Funcionais

#### Task 11.1 — Model TaskStatusChange + Observer

- [ ] Tabela `task_status_changes` com: `id`, `task_id` (FK), `status` (string/enum), `changed_at` (timestamp), `timestamps`
- [ ] Model `TaskStatusChange` com cast de `status` para `TaskStatus` enum e `changed_at` para datetime
- [ ] Factory `TaskStatusChangeFactory` com states uteis para testes
- [ ] Observer `TaskObserver` registrado no `AppServiceProvider` que:
  - No `created`: registra o status inicial da task
  - No `updating`: detecta mudanca de `status` e registra novo `TaskStatusChange`
- [ ] Relationship `Task::statusChanges()` (hasMany, ordenado por `changed_at`)
- [ ] Accessor `Task::timePerStatus` que retorna array associativo `[status => duracao_em_minutos]`
- [ ] Accessor `Task::currentStatusDuration` que retorna minutos no status atual
- [ ] Seeder atualizado para gerar `TaskStatusChange` retroativos para tasks existentes
- [ ] Testes cobrindo: criacao automatica no create, registro no update de status, calculo de tempo por status, accessor de duracao atual

#### Task 11.2 — Visualizacao no Task Modal + Dashboard + MCP

- [ ] **Task Modal**: Barra horizontal segmentada (stacked bar) mostrando proporcao de tempo em cada status, com cores do `TaskStatus::color()`, tooltip com tempo absoluto
- [ ] **Dashboard**: Cards de metricas com tempo medio por status (media de todas as tasks done), tempo medio total (inbox → done), task mais rapida/lenta
- [ ] **GetTaskTool**: Incluir `status_timeline` no response com historico de mudancas e `time_per_status` com duracao em cada status

### Nao-Funcionais

- Interface em PT-BR (labels, tooltips, metricas)
- Codigo em ingles (variaveis, classes, metodos)
- Dark mode only
- SFC (Single-File Components) para views
- Testes Feature com Pest para toda logica de negocio
- Performance: eager loading de `statusChanges` quando necessario, evitar N+1
- Observer nao deve impactar performance de operacoes existentes (leve, sincrono)
- Retrocompatibilidade: tasks existentes sem historico devem funcionar gracefully (retornar dados vazios ou estimados)

---

## 3. Analise do Codebase

### Estrutura Relevante

```
app/
  Models/
    Task.php              # Model principal — sera modificado
                          # Scopes: inbox, active, byStatus, overdue, unassigned, doneThisWeek
                          # Metodos: isOverdue(), isRunning(), markAsDone()
                          # Relations: project(), timeEntries(), dailyPlans()
                          # Casts: status → TaskStatus, priority → TaskPriority
    TimeEntry.php         # Referencia de padrao para model com FK para tasks
    Project.php           # Referencia de padrao para model com enums
    DailyPlan.php         # Referencia de padrao para model com timestamps

  Enums/
    TaskStatus.php        # Inbox, Backlog, Todo, Doing, Done
                          # Metodos: label(), color(), icon()
                          # NOTA: Nao tem "Review" — apenas 5 status

  Providers/
    AppServiceProvider.php  # Sera modificado para registrar TaskObserver

  Mcp/Tools/
    GetTaskTool.php       # Sera modificado para incluir timeline
    UpdateTaskTool.php    # Muda status via $task->update() — Observer captura
    CreateTaskTool.php    # Cria task — Observer captura status inicial

  Livewire/
    Actions/Logout.php    # Unico arquivo — componentes sao SFC em views/

resources/views/
  components/
    ⚡task-modal.blade.php   # Sera modificado — adicionar barra de tempo
  pages/
    ⚡dashboard.blade.php    # Sera modificado — adicionar metricas
    ⚡kanban.blade.php       # Muda status via handleSort — Observer captura
    ⚡daily-planner.blade.php # Muda status via toggleTask — Observer captura
    ⚡inbox.blade.php        # Nao muda status diretamente

  components/
    ⚡command-palette.blade.php # Muda status via moveTask — Observer captura

database/
  factories/
    TaskFactory.php       # States: done, doing, todo, backlog, overdue, urgent
    TimeEntryFactory.php  # Referencia para factory com FK
  seeders/
    DatabaseSeeder.php    # Sera modificado para gerar historico de status
  migrations/
    2026_02_13_110225_create_tasks_table.php  # Referencia de padrao

tests/Feature/
  TaskTest.php            # Sera modificado — testes de accessors
  TaskModalTest.php       # Sera modificado — testes da barra de tempo
  DashboardTest.php       # Sera modificado — testes das metricas
  Mcp/TaskToolsTest.php   # Sera modificado — testes do timeline no GetTaskTool
```

### Padroes Identificados

1. **Models**: `HasFactory`, `$fillable`, `casts()` method, PHPDoc blocks, return type hints em todas as relations/scopes
2. **Enums**: Backed string enums com `label()`, `color()`, `icon()` — TitleCase keys
3. **Factories**: States como metodos fluentes (`->done()`, `->running()`)
4. **Observers**: **Nao existem observers no projeto** — sera o primeiro. Laravel 12 registra via `$model->observe()` no `AppServiceProvider::boot()` ou via atributo `#[ObservedBy]`
5. **SFC Format**: `<?php ... new class extends Component { ... } ?> <div>...</div>`
6. **Testes**: Pest, `beforeEach` com `actingAs`, `Livewire::test()`, factory states
7. **Accessors**: Usam `Illuminate\Database\Eloquent\Casts\Attribute` (ver `TimeEntry::durationMinutes()`)
8. **Migrations**: Padrao Laravel com `Schema::create`, `$table->foreignId()->constrained()`
9. **Seeder**: Dados realistas hardcoded (nao usa factories no seeder principal)
10. **MCP Tools**: `Tool` base class, `handle(Request)`, `schema(JsonSchema)`, `Response::text(json_encode())`

### Pontos de Mudanca de Status (onde Observer sera acionado)

| Local | Metodo | Tipo de Mudanca |
|-------|--------|-----------------|
| `Task::markAsDone()` | `$this->update(['status' => Done])` | Qualquer → Done |
| `⚡kanban.blade.php` | `handleSort()` → `$task->update(['status' => $newStatus])` | Qualquer → Qualquer |
| `⚡task-modal.blade.php` | `saveTask()` → `$task->update(['status' => ...])` | Qualquer → Qualquer |
| `⚡command-palette.blade.php` | `moveTask()` → `$task->update(['status' => ...])` | Qualquer → Qualquer |
| `⚡daily-planner.blade.php` | `toggleTask()` → `$task->update(['status' => Doing])` | Done → Doing |
| `UpdateTaskTool.php` | `handle()` → `$task->update(['status' => ...])` | Qualquer → Qualquer |
| `CreateTaskTool.php` | `handle()` → `Task::create([...])` | Novo → Status inicial |
| `DatabaseSeeder.php` | `Task::create([...])` | Novo → Status inicial |

**Ponto critico**: O Observer no evento `updating` captura TODAS essas mudancas automaticamente, sem precisar modificar nenhum desses arquivos. O evento `created` captura a criacao inicial.

---

## 4. Dependencias

### Externas (ja instaladas — nenhuma nova necessaria)

| Pacote | Versao | Uso |
|--------|--------|-----|
| `laravel/framework` | 12.51.0 | Eloquent Observer, migrations, model events |
| `livewire/livewire` | 4.1.4 | SFC, componentes reativos |
| `livewire/flux-pro` | 2.12.0 | Badge, Tooltip, componentes visuais |
| `tailwindcss` | 4.1.18 | Barra segmentada, cores por status |
| `pestphp/pest` | 4.3.2 | Testes |

### Internas (ja existem)

| Artefato | Caminho | Necessario para |
|----------|---------|-----------------|
| Model Task | `app/Models/Task.php` | Adicionar relation + accessors |
| Enum TaskStatus | `app/Enums/TaskStatus.php` | Cast no TaskStatusChange, cores na barra |
| Factory TaskFactory | `database/factories/TaskFactory.php` | Testes |
| AppServiceProvider | `app/Providers/AppServiceProvider.php` | Registrar observer |
| GetTaskTool | `app/Mcp/Tools/GetTaskTool.php` | Adicionar timeline |
| Task Modal SFC | `resources/views/components/⚡task-modal.blade.php` | Adicionar barra |
| Dashboard SFC | `resources/views/pages/⚡dashboard.blade.php` | Adicionar metricas |
| DatabaseSeeder | `database/seeders/DatabaseSeeder.php` | Gerar historico retroativo |

### Modulos Afetados (impacto indireto)

- **Todos os locais que mudam status de task** serao automaticamente rastreados pelo Observer — sem modificacao de codigo nesses locais
- Testes existentes que criam/atualizam tasks podem gerar `TaskStatusChange` records como efeito colateral — verificar se isso causa problemas

---

## 5. Riscos e Mitigacoes

| # | Risco | Probabilidade | Impacto | Mitigacao |
|---|-------|---------------|---------|-----------|
| 1 | **Observer impacta testes existentes** — Ao registrar o observer, todos os testes que criam/atualizam tasks passarao a gerar `TaskStatusChange` records. Isso pode causar falhas em assertions de contagem ou side effects inesperados. | Alta | Medio | Rodar suite completa apos implementar observer. Os records extras nao devem causar problemas pois sao em tabela separada. Se necessario, desabilitar observer em testes especificos com `Task::withoutEvents()`. |
| 2 | **Performance do accessor `timePerStatus`** — Calcular tempo em cada status requer carregar todos os `statusChanges` e iterar. Para tasks com muitas mudancas, pode ser lento. | Baixa | Baixo | Usar eager loading (`with('statusChanges')`) quando necessario. O calculo e simples (diff entre timestamps consecutivos). Tasks tipicamente tem < 10 mudancas de status. |
| 3 | **Retrocompatibilidade com tasks existentes** — Tasks criadas antes do observer nao terao historico de status. | Alta | Medio | No seeder, gerar historico retroativo. Para tasks existentes em producao, o accessor deve retornar gracefully (array vazio ou estimativa baseada em `created_at` e status atual). |
| 4 | **Observer no `created` vs `creating`** — Usar `created` (apos persistir) para ter o `id` da task disponivel para criar o `TaskStatusChange`. | Baixa | Baixo | Usar evento `created` (nao `creating`). O `id` ja esta disponivel. |
| 5 | **Mudancas de status em batch/bulk** — `Task::query()->where(...)->update(['status' => ...])` NAO dispara observers (Eloquent mass update). | Media | Alto | Verificar se existem bulk updates de status no codebase. Atualmente, `markAsDone()` usa `$this->update()` (model instance, dispara observer). `TimeEntry::stopAllRunning()` e bulk mas nao muda status de task. **Nao ha bulk updates de task status no codebase atual.** |
| 6 | **`markAsDone()` dispara observer duas vezes?** — `markAsDone()` faz `$this->update(['status' => Done, 'completed_at' => now()])`. O observer `updating` sera chamado uma vez. Sem risco de duplicacao. | Baixa | Baixo | Nenhuma mitigacao necessaria. O observer verifica `$task->isDirty('status')` para so registrar quando status realmente muda. |
| 7 | **Dashboard com muitas tasks** — Calcular metricas de tempo medio para todas as tasks done pode ser pesado. | Media | Medio | Usar queries agregadas no banco (AVG, SUM) em vez de carregar todos os models. Ou limitar a tasks dos ultimos 30 dias. |
| 8 | **Barra segmentada com status sem tempo** — Se uma task pulou um status (ex: Inbox → Doing direto), a barra nao mostra esse status. | Baixa | Baixo | Comportamento esperado — so mostra status onde a task realmente esteve. A barra reflete a realidade. |
| 9 | **CarbonImmutable** — O projeto usa `Date::use(CarbonImmutable::class)`. Accessors de calculo de tempo devem usar `CarbonImmutable` corretamente. | Baixa | Baixo | Usar `->diffInMinutes()` que funciona igual em Carbon e CarbonImmutable. Testar com datas reais. |

---

## 6. Decisoes Tecnicas

### 6.1 Estrutura da Tabela `task_status_changes`

```php
Schema::create('task_status_changes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('task_id')->constrained()->cascadeOnDelete();
    $table->string('status');       // Valor do TaskStatus enum
    $table->timestamp('changed_at'); // Quando a mudanca ocorreu
    $table->timestamps();

    $table->index(['task_id', 'changed_at']);
});
```

**Decisao**: Usar `changed_at` separado de `created_at` para permitir insercao retroativa (seeder) com timestamps diferentes.

### 6.2 Observer — Eventos `created` e `updating`

```php
class TaskObserver
{
    public function created(Task $task): void
    {
        $task->statusChanges()->create([
            'status' => $task->status,
            'changed_at' => $task->created_at ?? now(),
        ]);
    }

    public function updating(Task $task): void
    {
        if ($task->isDirty('status')) {
            $task->statusChanges()->create([
                'status' => $task->status, // novo valor (ja atualizado no model)
                'changed_at' => now(),
            ]);
        }
    }
}
```

**Nota**: No evento `updating`, `$task->status` ja contem o NOVO valor (o Eloquent ja aplicou o set). Usar `$task->getOriginal('status')` para o valor antigo se necessario.

**Correcao**: Na verdade, no `updating`, `$task->status` retorna o novo valor e `$task->getOriginal('status')` retorna o antigo. Devemos registrar o NOVO status.

### 6.3 Registro do Observer

Opcao A — Atributo no Model (Laravel 12 preferred):
```php
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[ObservedBy(TaskObserver::class)]
class Task extends Model { ... }
```

Opcao B — No AppServiceProvider:
```php
public function boot(): void
{
    Task::observe(TaskObserver::class);
}
```

**Decisao**: Usar **Opcao A** (`#[ObservedBy]`) por ser o padrao moderno do Laravel 12 e manter o registro proximo ao model.

### 6.4 Accessor `timePerStatus`

```php
/**
 * Get time spent in each status in minutes.
 *
 * @return array<string, float>
 */
protected function timePerStatus(): Attribute
{
    return Attribute::get(function (): array {
        $changes = $this->statusChanges->sortBy('changed_at');

        if ($changes->isEmpty()) {
            return [];
        }

        $result = [];
        $previous = null;

        foreach ($changes as $change) {
            if ($previous !== null) {
                $status = $previous->status->value;
                $minutes = $previous->changed_at->diffInMinutes($change->changed_at);
                $result[$status] = ($result[$status] ?? 0) + $minutes;
            }
            $previous = $change;
        }

        // Tempo no status atual (ultimo change ate agora)
        if ($previous !== null) {
            $status = $previous->status->value;
            $minutes = $previous->changed_at->diffInMinutes(now());
            $result[$status] = ($result[$status] ?? 0) + $minutes;
        }

        return $result;
    });
}
```

### 6.5 Barra Segmentada no Task Modal

Barra horizontal com segmentos proporcionais ao tempo em cada status, usando cores do `TaskStatus::color()`:

```html
<div class="flex h-2 w-full overflow-hidden rounded-full">
    @foreach ($timePerStatus as $status => $minutes)
        @php $statusEnum = TaskStatus::from($status); @endphp
        <div
            class="bg-{{ $statusEnum->color() }}-500"
            style="width: {{ $percentage }}%"
            title="{{ $statusEnum->label() }}: {{ $formattedTime }}"
        ></div>
    @endforeach
</div>
```

### 6.6 Metricas no Dashboard

Queries agregadas para performance:

```php
// Tempo medio total (inbox → done) para tasks concluidas
$avgCycleTime = TaskStatusChange::query()
    ->selectRaw('task_id, MIN(changed_at) as first_change, MAX(changed_at) as last_change')
    ->whereIn('task_id', Task::done()->pluck('id'))
    ->groupBy('task_id')
    ->get()
    ->avg(fn ($row) => Carbon::parse($row->first_change)->diffInMinutes($row->last_change));
```

### 6.7 Seeder — Historico Retroativo

Para cada task no seeder, gerar mudancas de status retroativas baseadas no status final:

```php
// Exemplo: task com status "doing" → gerar: inbox(criacao) → backlog → todo → doing
private function createStatusHistory(Task $task): void
{
    $statusFlow = [TaskStatus::Inbox, TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::Doing, TaskStatus::Done];
    $targetIndex = array_search($task->status, $statusFlow);

    $baseTime = $task->created_at;
    for ($i = 0; $i <= $targetIndex; $i++) {
        TaskStatusChange::create([
            'task_id' => $task->id,
            'status' => $statusFlow[$i],
            'changed_at' => $baseTime->addHours(rand(1, 48)),
        ]);
    }
}
```

---

## 7. Escopo

### Incluido

#### Task 11.1
- Migration `create_task_status_changes_table`
- Model `app/Models/TaskStatusChange.php`
- Factory `database/factories/TaskStatusChangeFactory.php`
- Observer `app/Observers/TaskObserver.php`
- Relationship `Task::statusChanges()`
- Accessors `Task::timePerStatus`, `Task::currentStatusDuration`
- Atributo `#[ObservedBy]` no Task model
- Atualizacao do `DatabaseSeeder` para gerar historico
- Testes: `tests/Feature/TaskStatusChangeTest.php`

#### Task 11.2
- Barra segmentada no `⚡task-modal.blade.php`
- Metricas de tempo no `⚡dashboard.blade.php`
- Timeline no `GetTaskTool.php`
- Testes: atualizacao de `TaskModalTest.php`, `DashboardTest.php`, `TaskToolsTest.php`

### Excluido

- Status "Review" (nao existe no enum atual — manter 5 status)
- Edicao manual do historico de status (somente automatico)
- Graficos/charts complexos (apenas barra segmentada e numeros)
- Notificacoes baseadas em tempo em status
- Exportacao de dados de tempo por status
- Filtros por tempo no kanban

---

## 8. Arquivos a Criar/Modificar

### Criar

| Arquivo | Tipo | Descricao |
|---------|------|-----------|
| `database/migrations/xxxx_create_task_status_changes_table.php` | Migration | Tabela de historico de mudancas de status |
| `app/Models/TaskStatusChange.php` | Model | Model para registros de mudanca de status |
| `database/factories/TaskStatusChangeFactory.php` | Factory | Factory para testes |
| `app/Observers/TaskObserver.php` | Observer | Registra mudancas de status automaticamente |
| `tests/Feature/TaskStatusChangeTest.php` | Teste | Testes do observer, model e accessors |

### Modificar

| Arquivo | Alteracao |
|---------|-----------|
| `app/Models/Task.php` | Adicionar `#[ObservedBy]`, relationship `statusChanges()`, accessors `timePerStatus` e `currentStatusDuration` |
| `database/seeders/DatabaseSeeder.php` | Adicionar metodo `createStatusHistory()` para gerar historico retroativo |
| `resources/views/components/⚡task-modal.blade.php` | Adicionar barra segmentada de tempo por status |
| `resources/views/pages/⚡dashboard.blade.php` | Adicionar cards de metricas de tempo |
| `app/Mcp/Tools/GetTaskTool.php` | Adicionar `status_timeline` e `time_per_status` no response |
| `tests/Feature/TaskModalTest.php` | Adicionar testes da barra de tempo |
| `tests/Feature/DashboardTest.php` | Adicionar testes das metricas |
| `tests/Feature/Mcp/TaskToolsTest.php` | Adicionar testes do timeline no GetTaskTool |

---

## 9. Proximos Passos

1. **Aprovar este documento de contexto**
2. **Encaminhar para task-breakdown** para criacao das User Stories detalhadas
3. **Implementar Task 11.1** — Model, migration, factory, observer, accessors, seeder, testes
4. **Implementar Task 11.2** — Barra no modal, metricas no dashboard, timeline no MCP, testes
5. **Rodar suite completa de testes** para verificar que observer nao quebrou nada
6. **Rodar Pint** para formatacao (`vendor/bin/pint --dirty --format agent`)
