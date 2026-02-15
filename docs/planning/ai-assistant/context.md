# Documento de Contexto: Epic 16 — AI Assistant (Triagem + Priorização)

## 1. Resumo Executivo

Implementar um AI Assistant para o SoloBoard que atua como coach de produtividade para dev solo. Usa a API do Claude/Anthropic via HTTP client do Laravel para:

1. **Task 16.1** — Serviço `AiAssistantService` com 3 métodos (`suggestDailyPlan`, `analyzeBacklog`, `detectPatterns`) + configuração em `config/soloboard.php` + integração com API Anthropic.
2. **Task 16.2** — Botões "Sugerir plano" no Daily Planner e "Analisar backlog" no Inbox, com feature flag, loading states e error handling.
3. **Task 16.3** — Seção "Insights" proativa no Dashboard com cache diário, ações navegáveis e opção "Ignorar".

A feature é inteiramente controlada por feature flag (`ai_enabled`), garantindo que o app funcione perfeitamente sem AI.

---

## 2. Requisitos

### Funcionais

#### Task 16.1 — Serviço AI + Configuração
- [ ] `AiAssistantService.php` em `app/Services/`
- [ ] Método `suggestDailyPlan(Collection $tasks, array $history): array` — recebe tasks disponíveis + histórico de completionRate, retorna lista ordenada de sugestões com razão
- [ ] Método `analyzeBacklog(Collection $tasks): array` — analisa tasks inbox/backlog, sugere: arquivar, priorizar, estimar tempo
- [ ] Método `detectPatterns(array $weeklyData): array` — detecta padrões: projetos abandonados, over-commitment, melhores horários
- [ ] Cada método monta system prompt contextualizado com dados do SoloBoard
- [ ] Response format: JSON estruturado parseado da resposta da API
- [ ] Configuração em `config/soloboard.php`: `ai_enabled`, `ai_api_key`, `ai_model`, `ai_insights_cache_hours`
- [ ] Variáveis de ambiente: `SOLOBOARD_AI_ENABLED`, `ANTHROPIC_API_KEY`, `SOLOBOARD_AI_MODEL`
- [ ] Gracefully disabled quando `ai_enabled=false` (retorna arrays vazios, sem exceções)
- [ ] Testes Feature com mock da API HTTP

#### Task 16.2 — AI no Daily Planner + Inbox
- [ ] Botão "Sugerir plano" no Daily Planner (só aparece se `ai_enabled`)
- [ ] Ao clicar: chama `suggestDailyPlan()` via Livewire action
- [ ] Modal/drawer com sugestões: lista de tasks com razão, botão "Adicionar ao plano" por sugestão, botão "Adicionar todas"
- [ ] Loading state com skeleton/spinner
- [ ] Botão "Analisar backlog" no Inbox (só aparece se `ai_enabled`)
- [ ] Ao clicar: chama `analyzeBacklog()`
- [ ] Sugestões por task: prioridade sugerida, projeto sugerido, sugestão de arquivar
- [ ] Botões "Aplicar" por sugestão
- [ ] Rate limit básico: máx 1 chamada por minuto por feature
- [ ] Fallback gracioso se API falha (toast de erro, sem crash)
- [ ] Testes Feature com mock de API

#### Task 16.3 — AI Insights no Dashboard
- [ ] Nova seção "Insights" no Dashboard (só se `ai_enabled`)
- [ ] Banners proativos com padrões negativos detectados
- [ ] Cada insight com ações: "Ver projeto", "Abrir inbox", "Ignorar"
- [ ] Cache de insights: gerar no máximo 1x por dia
- [ ] "Ignorar" esconde o insight por 7 dias
- [ ] Testes Feature com mock de patterns

### Não-Funcionais
- Interface em PT-BR (labels, botões, mensagens, toasts)
- Código em inglês (variáveis, classes, métodos)
- Dark mode only (padrão do projeto)
- SFC (Single-File Components) para views — sem arquivos PHP separados em `app/Livewire/`
- Testes Feature com Pest para toda lógica de negócio
- Sem dependências externas novas (usar HTTP client nativo do Laravel)
- Feature flag garante zero impacto quando AI desabilitada
- Secrets (API key) nunca expostos no frontend

---

## 3. Análise do Codebase

### Estrutura Relevante

```
app/
├── Actions/
│   └── Fortify/                     # Actions do Fortify (auth)
├── Concerns/
│   ├── PasswordValidationRules.php
│   └── ProfileValidationRules.php
├── Console/                         # Comandos artisan
├── Enums/
│   ├── TaskStatus.php               # Inbox, Backlog, Todo, Doing, Done (com label PT-BR, color, icon)
│   ├── TaskPriority.php             # Urgent, High, Medium, Low
│   ├── ProjectStatus.php            # Active, Paused, Archived
│   └── ProjectPriority.php          # High, Medium, Low
├── Http/
│   ├── Controllers/
│   └── Middleware/
├── Livewire/
│   └── Actions/
│       └── Logout.php               # Único Livewire class-based
├── Mcp/                             # MCP Server (tools, resources, prompts)
│   ├── Prompts/
│   ├── Resources/
│   ├── Servers/
│   │   └── SoloBoardServer.php
│   └── Tools/                       # 13 MCP tools
├── Models/
│   ├── Task.php                     # Scopes: inbox, active, byStatus, overdue, unassigned, doneThisWeek
│   ├── Project.php                  # Scopes: active, paused, archived, ordered
│   ├── DailyPlan.php                # getOrCreateForDate, completionRate, incompleteTasks
│   ├── TimeEntry.php                # Scopes: running, forDate, forWeek, focusSessions
│   ├── TaskCommit.php               # Git commits associados a tasks
│   ├── TaskStatusChange.php         # Histórico de mudanças de status
│   ├── WeeklyReview.php             # completedTasks, totalHours, hoursByProject, staleTasks
│   └── User.php
├── Observers/
│   └── TaskObserver.php             # Registra TaskStatusChange em created/updating
├── Providers/
│   ├── AppServiceProvider.php
│   └── FortifyServiceProvider.php
├── Services/                        # NÃO EXISTE — será criado

config/
├── soloboard.php                    # Apenas mcp_key atualmente

resources/views/
├── layouts/
│   ├── app.blade.php                # Layout wrapper (hotkeys, command-palette)
│   └── app/
│       └── sidebar.blade.php        # Sidebar com nav + global components
├── pages/
│   ├── ⚡dashboard.blade.php        # SFC: deepWorkToday, averageTimeByStatus
│   ├── ⚡daily-planner.blade.php    # SFC: plan, availableTasks, addToPlan, toggleTask, carryOver
│   ├── ⚡inbox.blade.php            # SFC: tasks, assignProject, moveToBacklog, deleteTask
│   ├── ⚡kanban.blade.php
│   ├── ⚡projects.blade.php
│   ├── ⚡project-detail.blade.php
│   ├── ⚡time-report.blade.php
│   └── ⚡weekly-review.blade.php
├── components/
│   ├── ⚡command-palette.blade.php
│   ├── ⚡global-timer.blade.php
│   ├── ⚡inbox-badge.blade.php
│   ├── ⚡task-modal.blade.php
│   ├── ⚡task-quick-add.blade.php
│   ├── ⚡timer.blade.php
│   └── ⚡timer-notes-modal.blade.php

tests/Feature/
├── DashboardTest.php                # 2 testes (auth + render)
├── DailyPlannerTest.php             # 26 testes (completo)
├── InboxTest.php                    # 19 testes (completo)
├── ...                              # 44 arquivos de teste total
```

### Padrões Identificados

1. **SFC (Single-File Components)**: Todas as pages usam formato `⚡nome.blade.php` com `new class extends Component {}` no topo. PHP no topo, HTML embaixo separado por `?>`.
2. **Sem diretório Services**: O projeto NÃO tem `app/Services/`. Será o primeiro Service criado.
3. **Computed Properties**: Uso extensivo de `#[Computed]` para dados derivados (ex: `$this->plan`, `$this->tasks`, `$this->availableTasks`).
4. **Eventos Livewire**: Comunicação entre componentes via `$this->dispatch('task-updated')`, `#[On('task-created')]`.
5. **Flux UI**: Componentes `<flux:*>` usados extensivamente — heading, text, icon, badge, button, table, modal, callout, toast, select, checkbox, textarea, date-picker.
6. **Toasts**: `Flux::toast(variant: 'success', heading: '...', text: '...')` para feedback.
7. **Dark mode only**: Cores `zinc-700/800/900`, borders `zinc-700`, backgrounds `zinc-800/50`, `zinc-900/50`.
8. **Testes Pest**: `beforeEach` com `$this->actingAs(User::factory()->create())`, `Livewire::test('pages::nome')`, assertions com `assertSee`, `assertSet`, `assertDispatched`.
9. **Factories com states**: `Task::factory()->todo()`, `->done()`, `->backlog()`, `->urgent()`, `->withEstimate()`.
10. **Models com casts()**: Método `casts()` (não property `$casts`), enums como cast.
11. **PHPDoc blocks**: Todos os métodos têm PHPDoc com `@return` tipado.
12. **Labels PT-BR**: Enums têm `label()` em português, UI toda em português.
13. **Unset computed**: `unset($this->plan, $this->availableTasks)` para invalidar cache de computed properties.

### Dados Existentes no Banco

| Entidade | Quantidade | Relevância para AI |
|----------|-----------|-------------------|
| Tasks | 37 (6 inbox, 9 backlog, 9 todo, 5 doing, 8 done) | Input principal para sugestões |
| Projects | 7 | Contexto de projetos ativos/pausados |
| Time Entries | 35 | Padrões de produtividade |
| Daily Plans | 2 | Histórico de planejamento |
| Weekly Reviews | 1 | Dados de reflexão semanal |

---

## 4. Dependências

### Externas (já instaladas — NENHUMA nova necessária)
- `laravel/framework` v12.51.0 — HTTP client (`Http::withHeaders()->post()`), Cache facade, config
- `livewire/livewire` v4.1.4 — SFC, wire:model, wire:click, eventos, computed
- `livewire/flux-pro` v2.12.0 — Modal, Button, Callout, Toast, Skeleton, Badge, Icon
- `tailwindcss` v4.1.18 — Estilização dark mode
- `pestphp/pest` v4.3.2 — Testes Feature
- `mockery/mockery` v1.6 — Mock de HTTP client nos testes

### Externas (API remota)
- **Anthropic Messages API** (`https://api.anthropic.com/v1/messages`) — Endpoint para chamadas AI
- Modelo padrão: `claude-sonnet-4-20250514`
- Autenticação: Header `x-api-key` + `anthropic-version: 2023-06-01`

### Internas — Módulos Afetados

| Artefato | Ação | Motivo |
|----------|------|--------|
| `app/Services/AiAssistantService.php` | **CRIAR** | Serviço principal de AI |
| `config/soloboard.php` | **MODIFICAR** | Adicionar configs de AI |
| `.env` / `.env.example` | **MODIFICAR** | Adicionar variáveis de ambiente |
| `resources/views/pages/⚡daily-planner.blade.php` | **MODIFICAR** | Botão "Sugerir plano" + modal de sugestões |
| `resources/views/pages/⚡inbox.blade.php` | **MODIFICAR** | Botão "Analisar backlog" + sugestões |
| `resources/views/pages/⚡dashboard.blade.php` | **MODIFICAR** | Seção "Insights" proativa |
| `tests/Feature/AiAssistantTest.php` | **CRIAR** | Testes do serviço |
| `tests/Feature/AiIntegrationTest.php` | **CRIAR** | Testes de integração UI |
| `tests/Feature/AiInsightsTest.php` | **CRIAR** | Testes de insights no dashboard |

### Models Consumidos pelo AI (somente leitura)

| Model | Dados Usados | Método AI |
|-------|-------------|-----------|
| `Task` | title, status, priority, due_date, estimated_minutes, completed_at, project_id, created_at | Todos |
| `Project` | name, status, priority, description | Todos |
| `DailyPlan` | date, tasks (pivot: completed_at) | suggestDailyPlan |
| `TimeEntry` | started_at, stopped_at, is_focus_session, task_id | detectPatterns |
| `TaskStatusChange` | from_status, to_status, changed_at | detectPatterns |
| `WeeklyReview` | completedTasks, totalHours, staleTasks | detectPatterns |

---

## 5. Riscos e Mitigações

| # | Risco | Probabilidade | Impacto | Mitigação |
|---|-------|---------------|---------|-----------|
| 1 | **API key exposta no frontend** — Livewire serializa propriedades públicas | Alta | Crítico | API key NUNCA como propriedade pública do componente. Usar `config()` apenas no Service (server-side). Verificar que nenhum dado sensível é exposto via wire:snapshot. |
| 2 | **Custo da API Anthropic** — Chamadas frequentes podem gerar custos altos | Média | Alto | Rate limit (1 chamada/minuto por feature), cache de insights (24h), feature flag para desabilitar. Usar modelo `claude-sonnet-4-20250514` (mais barato que opus). |
| 3 | **Latência da API** — Chamadas à API podem demorar 5-15s | Alta | Médio | Loading states com skeleton/spinner. Timeout configurável no HTTP client (30s). Toast de erro se timeout. Não bloquear a UI. |
| 4 | **Parsing de JSON da resposta AI** — LLM pode retornar JSON malformado | Média | Médio | Instruir formato JSON no system prompt. Usar `json_decode` com fallback. Validar estrutura da resposta. Retornar array vazio se parsing falhar. |
| 5 | **Primeiro Service do projeto** — Não há padrão estabelecido para Services | Baixa | Baixo | Seguir convenções Laravel padrão. Injetar via constructor nos componentes Livewire ou usar `app(AiAssistantService::class)`. |
| 6 | **Cache de insights com dados stale** — Insights cacheados podem ficar desatualizados | Baixa | Baixo | Cache de 24h é aceitável para insights proativos. Botão "Atualizar" para forçar refresh. Cache key inclui data do dia. |
| 7 | **Testes dependem de mock HTTP** — Testes não devem chamar API real | Alta | Alto | Usar `Http::fake()` do Laravel para mockar respostas da API Anthropic em todos os testes. Criar fixtures de resposta JSON. |
| 8 | **Feature flag off por padrão** — Usuários podem não saber que AI existe | Baixa | Baixo | Documentar no `.env.example`. Considerar hint sutil no dashboard quando AI está desabilitada. |

---

## 6. Tecnologias e Ferramentas

| Tecnologia | Versão | Uso |
|------------|--------|-----|
| PHP | 8.4.17 | Backend, Service class |
| Laravel | 12.51.0 | HTTP client, Cache, Config, Service Container |
| Laravel HTTP Client | (built-in) | `Http::withHeaders()->post()` para API Anthropic |
| Laravel Cache | (built-in) | `Cache::remember()` para insights diários |
| Livewire | 4.1.4 | SFC, actions para chamar AI, computed properties |
| Flux UI Pro | 2.12.0 | Modal, Button, Callout, Toast, Skeleton, Badge |
| Alpine.js | (bundled) | Loading states, interações client-side |
| Tailwind CSS | 4.1.18 | Estilização dark mode |
| Pest | 4.3.2 | Testes Feature |
| Mockery | 1.6 | Mock de HTTP responses |
| Anthropic API | Messages v1 | `https://api.anthropic.com/v1/messages` |
| SQLite | — | Banco de dados (cache table já existe) |

---

## 7. Escopo

### Incluído
- Criação de `app/Services/AiAssistantService.php` com 3 métodos
- Atualização de `config/soloboard.php` com configurações de AI
- Atualização de `.env` e `.env.example` com variáveis de ambiente
- Integração no Daily Planner: botão "Sugerir plano" + modal de sugestões
- Integração no Inbox: botão "Analisar backlog" + sugestões por task
- Seção "Insights" no Dashboard com cache diário
- Feature flag `ai_enabled` (default: false)
- Rate limiting básico (1 chamada/minuto por feature)
- Loading states e error handling
- Testes Feature com mock HTTP para os 3 cenários
- Ação "Ignorar" insight por 7 dias

### Excluído
- Streaming de respostas (SSE/WebSocket) — respostas são síncronas
- Histórico de conversas com AI — cada chamada é stateless
- Customização de prompts pelo usuário
- Integração com outros LLMs além do Anthropic
- Billing/usage tracking da API
- AI para geração de código ou session prompts (já existe via MCP)
- Testes de integração real com API (apenas mocks)

---

## 8. Decisões Técnicas Recomendadas

### 8.1 Estrutura do AiAssistantService

```php
// app/Services/AiAssistantService.php
class AiAssistantService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly bool $enabled,
    ) {}

    public function isEnabled(): bool;
    public function suggestDailyPlan(Collection $tasks, array $history): array;
    public function analyzeBacklog(Collection $tasks): array;
    public function detectPatterns(array $weeklyData): array;

    // Private helpers
    private function callApi(string $systemPrompt, string $userMessage): array;
    private function buildDailyPlanPrompt(Collection $tasks, array $history): array;
    private function buildBacklogPrompt(Collection $tasks): array;
    private function buildPatternsPrompt(array $weeklyData): array;
    private function parseJsonResponse(string $content): array;
}
```

**Registro no Service Container**: Via `AppServiceProvider::register()` com binding que lê config:

```php
$this->app->singleton(AiAssistantService::class, fn ($app) => new AiAssistantService(
    apiKey: config('soloboard.ai_api_key', ''),
    model: config('soloboard.ai_model', 'claude-sonnet-4-20250514'),
    enabled: config('soloboard.ai_enabled', false),
));
```

### 8.2 Configuração

```php
// config/soloboard.php
return [
    'mcp_key' => env('SOLOBOARD_MCP_KEY'),

    'ai_enabled' => env('SOLOBOARD_AI_ENABLED', false),
    'ai_api_key' => env('ANTHROPIC_API_KEY'),
    'ai_model' => env('SOLOBOARD_AI_MODEL', 'claude-sonnet-4-20250514'),
    'ai_insights_cache_hours' => env('SOLOBOARD_AI_INSIGHTS_CACHE_HOURS', 24),
];
```

### 8.3 Chamada à API Anthropic

Usar o HTTP client nativo do Laravel:

```php
$response = Http::withHeaders([
    'x-api-key' => $this->apiKey,
    'anthropic-version' => '2023-06-01',
    'content-type' => 'application/json',
])
->timeout(30)
->post('https://api.anthropic.com/v1/messages', [
    'model' => $this->model,
    'max_tokens' => 1024,
    'system' => $systemPrompt,
    'messages' => [
        ['role' => 'user', 'content' => $userMessage],
    ],
]);
```

### 8.4 Formato de Resposta Esperado

**suggestDailyPlan:**
```json
{
    "suggestions": [
        {
            "task_id": 12,
            "reason": "Alta prioridade, vencendo amanhã",
            "order": 1
        }
    ]
}
```

**analyzeBacklog:**
```json
{
    "analysis": [
        {
            "task_id": 5,
            "action": "prioritize",
            "suggested_priority": "high",
            "reason": "Vencendo em 2 dias, sem atividade"
        },
        {
            "task_id": 8,
            "action": "archive",
            "reason": "Criada há 30 dias, sem atividade"
        }
    ]
}
```

**detectPatterns:**
```json
{
    "insights": [
        {
            "type": "abandoned_project",
            "severity": "warning",
            "message": "Projeto Mobile App sem atividade há 14 dias",
            "action_label": "Ver projeto",
            "action_route": "project.detail",
            "action_params": {"slug": "mobile-app"}
        }
    ]
}
```

### 8.5 Rate Limiting

Usar Laravel Cache para rate limiting simples:

```php
$cacheKey = "ai_rate_limit:{$feature}";
if (Cache::has($cacheKey)) {
    // Retornar toast "Aguarde 1 minuto entre chamadas"
    return;
}
Cache::put($cacheKey, true, now()->addMinute());
```

### 8.6 Cache de Insights (Task 16.3)

```php
$insights = Cache::remember(
    'ai_insights:' . now()->toDateString(),
    now()->addHours(config('soloboard.ai_insights_cache_hours', 24)),
    fn () => $this->aiService->detectPatterns($weeklyData)
);
```

### 8.7 "Ignorar" Insight

Usar Cache com key por insight hash:

```php
// Ao clicar "Ignorar"
Cache::put("ai_insight_dismissed:{$insightHash}", true, now()->addDays(7));

// Ao renderizar
$insights = collect($insights)->reject(
    fn ($insight) => Cache::has("ai_insight_dismissed:" . md5($insight['message']))
);
```

### 8.8 Integração nos SFC (Padrão)

Os componentes SFC (Daily Planner, Inbox, Dashboard) receberão:
- Propriedade pública `array $aiSuggestions = []` para armazenar resultados
- Propriedade pública `bool $aiLoading = false` para loading state
- Método Livewire action (ex: `suggestPlan()`) que chama o Service
- Verificação `config('soloboard.ai_enabled')` no Blade com `@if`

### 8.9 Testes (Padrão)

```php
// Mockar HTTP
Http::fake([
    'api.anthropic.com/*' => Http::response([
        'content' => [['type' => 'text', 'text' => json_encode($expectedResponse)]],
    ]),
]);

// Ou mockar o Service inteiro
$this->mock(AiAssistantService::class, function ($mock) {
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('suggestDailyPlan')->andReturn([...]);
});
```

---

## 9. Plano de Implementação (Ordem Recomendada)

### Fase 1 — Task 16.1: Serviço AI + Configuração
1. Criar `app/Services/AiAssistantService.php`
2. Atualizar `config/soloboard.php`
3. Atualizar `.env` e `.env.example`
4. Registrar Service no `AppServiceProvider`
5. Criar `tests/Feature/AiAssistantTest.php`
6. Rodar testes + Pint

### Fase 2 — Task 16.2: AI no Daily Planner + Inbox
1. Modificar `⚡daily-planner.blade.php` — adicionar botão, action, modal de sugestões
2. Modificar `⚡inbox.blade.php` — adicionar botão, action, sugestões inline
3. Criar `tests/Feature/AiIntegrationTest.php`
4. Rodar testes + Pint

### Fase 3 — Task 16.3: AI Insights no Dashboard
1. Modificar `⚡dashboard.blade.php` — adicionar seção Insights
2. Implementar cache de insights
3. Implementar "Ignorar" com cache de 7 dias
4. Criar `tests/Feature/AiInsightsTest.php`
5. Rodar testes + Pint

---

## 10. Próximos Passos

1. **Aprovar este documento de contexto**
2. **Encaminhar para task-breakdown** para criação das User Stories detalhadas
3. **Implementar na ordem**: Task 16.1 → 16.2 → 16.3
4. **Rodar testes completos** após cada task
5. **Rodar Pint** para formatação após cada commit
