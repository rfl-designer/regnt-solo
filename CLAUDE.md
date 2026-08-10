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

- Models: `App\Models\` — Project, Task, TimeEntry, MorningRitual, TaskStatusChange, WeeklyReview, RecurringTask, TaskTemplate
- Enums: `App\Enums\` (PHP native enums) — cada enum implementa `label()` (PT-BR), `color()`, `icon()`
- `Task.completed_at`: preenchido ao marcar done
- `Task.sort_order`: por coluna (por status)
- `TimeEntry`: cascade delete com Task
- `TaskStatusChange`: cascade delete com Task — registra automaticamente cada mudança de status
- Sem subtasks — tasks são flat, descrição markdown via `<flux:editor>`

## Decisões de Design

- **Dark mode only** (sem toggle, sem light mode)
- **Kanban**: 7 colunas em ordem de fluxo (Backlog → Aguardando aprovação → Pronto → Fazendo → Esperando → Aguardando validação → Feito). Feito mostra só as não-arquivadas
- **Kanban**: lazy loading 20 por coluna, seção "Sem projeto" separada
- **Ritual matinal** (substitui o Daily Planner): wizard de 5 passos de um clique + tela de registro (`/ritual`). Arquivar é timestamp (`archived_at`), nunca status — métricas de fluxo não mudam
- **Timer**: apenas 1 ativo por vez, mini modal de notas ao parar (bloqueia)
- **Task Modal**: NÃO fecha ao salvar (reativa), TimeEntries editáveis inline, barra de tempo por status
- **Quick-Add**: modal overlay (hotkey `Ctrl+N`), sempre cria inbox, autocomplete `#projeto` `!prioridade` `@data`
- **Command Palette** (`Ctrl+K`): busca + 5 comandos com prefixo `>` (`mover`, `timer`, `deletar`, `projeto`, `classe`)
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
  - Captura mudanças de qualquer origem (Kanban, Task Modal, Command Palette, Ritual matinal, MCP)
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
  - `analyzeBacklog(Collection $tasks): array` — analisa inbox/backlog e sugere ações (arquivar, priorizar, estimar)
  - `detectPatterns(array $weeklyData): array` — detecta padrões de produtividade (projetos abandonados, over-commitment, falta de deep work)
  - `refineUpdate(string $draft): string` — revisa o texto do update semanal como copy editor (issue #151)
- **Graceful degradation**: todos os métodos retornam vazio (`[]` ou `''`) se desabilitado, sem entrada, ou em caso de erro da API

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
- `tests/Feature/AiIntegrationTest.php` — testes de integração no Inbox
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

## Update semanal por cliente (issue #149)

Gerador determinístico do update semanal, com fila por urgência, rascunho persistido e histórico. Página `/updates` (sidebar, categoria Acompanhamento).

- **Model `ClientUpdate`**: `client_id`, `content`, `generated_content`, `sent_at` (null = rascunho da semana)
  - `hasManualEdits()` compara `content` com `generated_content` **byte a byte** (sem `trim()`: em Markdown o espaço é conteúdo) — é o que faz "Regenerar" pedir confirmação só quando há texto humano a perder
  - `sent_at` não é fillable: só `ClientUpdateService::markSent()` o carimba
  - **Um rascunho aberto por cliente, garantido pelo banco**: `draft_client_id` (derivada no `saving`, = `client_id` enquanto rascunho, NULL depois do envio) com índice único. `generate()` roda em transação com a linha do cliente travada
- **Enum `HillPosition`**: `uphill` ("Em descoberta") / `downhill` ("Em execução") — coluna `activities.hill_position`, nullable, marcada à mão no modal do Épico
- **Enum `UpdateUrgency`**: `event` / `overdue` / `due_today` / `on_track` (nesta ordem na fila)
- **Serviço `ClientUpdateService`** (um lugar computa; página, badge e MCP consomem):
  - `queue()` / `dueCount()` — fila de clientes ativos por urgência. **A urgência é uma pergunta de dia**: no dia da cadência o cliente vence hoje de meia-noite a meia-noite, e só no dia seguinte fica atrasado. A hora-alvo (`update_time`, default 12:00) é o instante que a fila mostra em `dueAt` e o desempate de um envio feito no próprio dia — nunca decide a urgência. Tudo no fuso de negócio (`MorningRitual::businessNow()`)
  - `windowStart()` — desde o último `sent_at`; primeiro update = 7 dias; **sem teto**
  - `blocks()` / `compose()` — os 4 blocos PT-BR (Entregue / Em andamento / Esperando você / Próximo), filtrados por cliente efetivo e `Activity::scopeSpecLevel()`, com bloco vazio omitido. **Entregue seleciona pelo evento na janela**, não pelo estágio atual: uma entrega devolvida para ajustes continua no bloco, com esse detalhe
  - `generate(force)` — persiste o rascunho; recusa descartar edição manual sem confirmação
  - `markSent()` — grava a data, fecha a janela e zera o relógio
  - `history(limit)` / `sentCount()` — histórico paginado
  - `triggersFor(clients)` — os gatilhos por evento (veja abaixo), numa varredura só para a fila inteira
- **Página `/updates`**: fila com ícone do canal, editor com autosave (`wire:model.live.blur`), Copiar (não grava) e Marcar como enviado (grava) separados, Regenerar com confirmação, histórico por cliente com "carregar mais"
  - O `force` do "Regenerar" sai de `#[Locked] $regenerateConfirmedFor` (o id do rascunho confirmado), não do booleano do modal: uma chamada Livewire direta a `generate`/`regenerate` pergunta em vez de apagar
- **Sidebar**: item "Updates" com badge da contagem de devidos (`⚡updates-badge`)
- **Clientes**: atalho "Gerar update" leva para `/updates?client={slug}`
- **Testes**: `ClientUpdateServiceTest`, `UpdatesPageTest`, `HillPositionTest`, `Mcp/ClientUpdateToolsTest`, `Browser/UpdatesFlowTest`

### Gatilhos de update por evento (issue #150)

Um cliente também entra na fila quando o quadro produz notícia que não espera a cadência. **Sem persistência nova** — nenhuma tabela, coluna ou flag de "gatilho disparado": a pergunta é refeita a cada leitura da fila, contra a janela desde o último envio. É isso que faz **enviar apagar o gatilho** (enviar abre janela nova) sem nada precisar ser marcado como lido. O update disparado é um update comum: enviar zera o relógio e antecipa a cadência.

- **Enum `UpdateTrigger`**: `emergency` ("Emergência") / `delivery_awaiting_validation` ("Entrega aguardando validação"). Ordem de declaração = ordem dos chips (a Emergência primeiro). Cores/ícones emprestados de `ServiceClass::Emergency` e `ActivityStatus::AwaitingValidation`
- **O que dispara** (`ClientUpdateService::triggersFor()`), sempre medido contra `windowStart()`:
  1. Uma spec do cliente (`scopeSpecLevel()`) **entrou em Aguardando validação** na janela. É o evento, não o estado: entregue e já validada depois continua disparando
  2. Uma **Emergência de item** do cliente (qualquer item, não só nível spec) foi classificada na janela (`emergency_since`) e está ativa, **ou** foi concluída na janela tendo `emergency_reason`
- **Por que a Emergência ativa também olha a janela**: "ativa agora" sozinho manteria o cliente em "evento" para sempre enquanto o fogo durasse, inclusive depois de um update que já falou dela. Datar pelo `emergency_since` mantém a promessa dos dois gatilhos — enviar apaga
- **Furo aceito e documentado**: uma Emergência **rebaixada** antes do envio some do radar. Rebaixar apaga `service_class`, `emergency_reason` e `emergency_since`, e não sobra nada no estado que prove que ela existiu. Fechar o furo exigiria persistência nova, que é o que a feature se recusa a ter
- **`ClientUpdateQueueEntry`**: `urgency` é a **categoria** (`Event` quando há gatilho), `cadence` é o degrau do relógio. As duas convivem: "atrasado há 3 dias" e `daysLate()` continuam saindo da cadência, e a badge/ordenação saem da categoria
- **A fronteira é estrita**: o evento tem de ser **posterior** ao envio, não simultâneo. `sent_at`, `changed_at` e `emergency_since` têm precisão de segundo, e enviar logo depois de mexer no quadro é o gesto normal — com `>=`, um evento carimbado no mesmo segundo do envio sobreviveria a ele e quebraria a promessa
- **Sem notificação ativa**: sem toast, sem banner. O gatilho espera na fila (chip na linha + badge da sidebar) em vez de interromper
- **Custo**: a varredura é **uma consulta para a fila inteira** — com ou sem candidatos, e não uma por cliente. A badge da sidebar roda isso em toda página, então nada de relação carregada: o cliente efetivo e as duas datas que decidem (`delivered_at`, `concluded_at`) vêm como colunas calculadas em subconsultas correlacionadas
- **MCP `get-update-queue`**: publica `urgency` (com `event`), `cadence` e `triggers[]` (`key`, `label`, `reason`); `only_due` e `due_count` incluem os eventos. As **instruções do servidor MCP** também descrevem a categoria e os gatilhos — um agente lê elas antes da descrição da tool, e há teste que impede a divergência

### Refinar o update com AI (issue #151)

Botão "✨ Refinar com AI" no editor do rascunho, atrás da mesma feature flag do Epic 16 (`ai_enabled` + chave). **A AI é copy editor, não redatora**: reescreve tom, fluidez, concisão e transições do rascunho atual — incluindo as edições feitas à mão —, e nada mais.

- **Contrato duro no prompt** (`AiAssistantService::buildRefineUpdatePrompt()`): proibido adicionar, remover ou alterar item, estado, data, número, nome ou compromisso; proibido resumir, fundir ou reordenar blocos; formatação que degrada bem em qualquer canal (WhatsApp, e-mail, Slack); tom fixo PT-BR profissional-próximo. A resposta é o texto refinado e mais nada — é a única chamada de AI que **não** responde JSON, e por isso o prompt não é montado sobre `BASE_SYSTEM_PROMPT` (a persona de coach convidaria exatamente as sugestões que o contrato proíbe)
- **O rascunho vai delimitado**: o texto segue entre marcas `<draft_update>`, e o system prompt declara que o que está lá dentro é conteúdo literal, nunca instrução — o rascunho passa pela mão do usuário e não pode virar prompt
- **Nunca escreve direto no editor**: o resultado abre num modal de preview. "Aplicar" substitui o conteúdo do editor (o autosave segue daí); "Descartar" não altera nada. `$aiRefinement` é `#[Locked]` — o preview é o que a API respondeu, não o que o browser mandar
- **O preview é preso ao rascunho que o gerou**: `$aiRefinementFor` (id) e `$aiRefinementSourceHash` (sha256 do texto enviado), ambos `#[Locked]`. "Aplicar" confere os dois contra o rascunho atual; se o rascunho foi editado noutra aba ou por MCP, ou foi enviado e recriado, o preview é descartado com toast em vez de escrever por cima. Trocar de cliente descarta pela mesma razão
- **Degradação graciosa**: erro da API, resposta vazia ou resposta truncada vira toast amigável e o rascunho fica intacto — `parseTextResponse()` só aceita `stop_reason === 'end_turn'`, porque um texto cortado no `max_tokens` chegaria sem o fim do rascunho e aplicá-lo apagaria itens. Sem flag, a barra é a mesma de antes e o fluxo determinístico continua inteiro
- **Rate limit de 1 chamada/minuto** via `Cache::add()` **antes** da chamada — `has` + `put` deixa dois cliques simultâneos passarem juntos. O preço explícito: uma falha da API também consome o minuto, porque é o pedido que se limita
- **Testes**: `AiAssistantTest` (contrato do prompt com API mockada, delimitação, truncamento, degradação) e `UpdatesPageTest` (flag, preview, aplicar/descartar, rascunho mudado ou recriado, erro, truncamento, rate limit e sua aquisição antes da chamada)

## Guarda de apetite estourado (issue #152)

O apetite escolhido no shaping vira orçamento vigiado. **Nada de novo é gravado**: o consumo é derivado do histórico de status, como todo o resto do Fluxo Solo — não há coluna de "dias gastos", e não deve haver.

- **A janela é a mesma da eficiência de fluxo**: `spec_aprovada` → `spec_validada`, correndo até **agora** enquanto a validação não vem. É o período em que a aposta é um compromisso vivo, que é justamente para o que o apetite é orçamento. Uma spec reaberta depois de validada volta a consumir sozinha, porque `Activity::specStage()` lê a ordem dos eventos, não quais datas existem
- **Serviço `FlowMetricsService::appetiteConsumption()`**: devolve `appetite_days`, `consumed_days`, `ratio`, `over_days`, `over_label` (`+3d`), `headline`, `level`, `open` e `label` (`"9 de 14 dias"`). Retorna **null sem aprovação** — sem janela não há aposta correndo, e um zero leria como "nada gasto"
- **No apetite exato o vermelho fica, o excedente não**: em 100% cravados `over_days` é 0, então `over_label` é null e a frase é "Apetite no limite" em vez de "Apetite estourado (+0d)". Quem escreve a frase é o serviço (`headline`), não cada template — as duas superfícies não podem divergir sobre o mesmo estado. Qualquer excedente positivo arredonda para cima e vira ao menos `+0,1d`
- **Limiares compartilhados com o aging**: âmbar em `sle_attention_percent` (80%), vermelho em 100%. Uma segunda escala faria o quadro alarmar atraso de um jeito e estouro de outro. O número da barra é arredondado *para longe* do limiar (`formatDays($days, roundUp:)`, o mesmo do aging), então texto e cor nunca se contradizem
- **Sem apetite é estado, não zero**: `level = no_appetite` — sem barra, sem alerta, em toda superfície. Comparar contra um orçamento que ninguém escolheu seria inventar o orçamento
- **Modal do Épico**: barra de consumo junto da timeline da Spec; ao estourar, banner "Apetite estourado (+Nd) — corte escopo ou mate a aposta" com atalho "Revisar escopo"
- **Página Fluxo**: seção "Apostas em andamento" (`FlowMetricsService::liveBets()` — specs nos estágios `aprovada` e `entregue`), estouradas no topo, depois por fração do apetite, e as sem apetite no fim. Custo fixo: uma consulta com o histórico em eager load, não uma por aposta
- **Página de shaping aceita Épico** (`Activity::scopeShapeable()`, rota `epic-shaping` em `epics/{draft}/shaping`): as mesmas 5 seções, porque cortar escopo é dar forma de novo. O rodapé troca promover/adiar por "Voltar ao quadro", e `promote()` recusa um Épico mesmo por chamada forjada (`#[Locked] $isEpic`). **Revisão não reatribui projeto**: `projectId` continua propriedade pública, então `updatedProjectId()` recusa a escrita quando `isEpic` e restaura o valor gravado — esconder o seletor é UI, a recusa é a regra
- **MCP**: as duas costuras publicam o **mesmo enum de 4 valores** (`ok`, `warning`, `exceeded`, `no_appetite`) e **null** enquanto não há aprovação — `list-epics` em `appetite_status`, `get-project-context` em `appetite.status` (com `consumed_days: null` dizendo que não há janela). Um quinto valor numa das duas quebraria quem valida o enum. `get-project-context` devolve `appetite: null` no que não é aposta. As instruções do servidor descrevem a guarda — o agente lê elas antes da descrição da tool
- **Kanban intocado**: nenhum alerta de apetite nos cards. O estouro é assunto da aposta, não da fatia
- **Testes**: `AppetiteGuardTest` (janela, parada na validação, reabertura, limiares, excedente, sem apetite, ordenação das apostas), `AppetiteGuardUiTest` (modal e página Fluxo), `ShapingPageTest` (revisão de escopo) e `Mcp/EpicToolsTest` + `Mcp/ProjectContextAppetiteTest` (costura MCP)

## Fome de Intangível (issue #153)

A classe que ninguém cobra só sobrevive se o quadro cobrar por ela. A fome é medida, não configurada por sensação, e **nada é gravado**: sai do `activity_status_changes` como todo o resto do Fluxo Solo.

- **A métrica é conclusão, não puxada**: dias desde a **última entrada em Feito** de um item classe `intangible`. Puxar sem concluir **não zera** — Intangível só paga quando termina, e um contador satisfeito por começar seria calado para sempre por um item parado em Fazendo
- **Limiar em config**: `soloboard.intangible_starvation_days`, default **14**, ao lado do WIP limit, da janela de risco e dos dials da SLE. Sem UI para editar, pelo mesmo motivo deles
- **Serviço `FlowMetricsService::intangibleStarvation()`**: devolve `days`, `days_label`, `threshold`, `starving`, `anchor`, `last_completed_at`, `ready_count`, `label` e `headline`. `readyIntangibles()` é o atalho — os Intangíveis em Pronto, mais velhos primeiro
- **Partida a frio ancora no corte**: sem nenhuma conclusão Intangível no histórico, o relógio conta do `BaselineCut` vigente (`anchor = cut`) e o alerta **dispara** — "nunca concluí um Intangível" é a fome máxima, não ausência de dado. Sem corte nenhum, ancora na primeira mudança de status já registrada (`board`); um quadro sem histórico algum não tem relógio e fica calado (`none`)
- **Um corte não zera a fome**: uma conclusão anterior ao corte continua contando (`anchor = completion`). O corte redefine a população da SLE, não desfaz o refactor — reancorar no corte esconderia meses de fome
- **Despensa vazia não cala**: `ready_count = 0` não suprime o alerta, só troca o remédio de "puxe um" para "crie ou promova um". Suprimir daria ao quadro um jeito de ficar quieto nunca criando trabalho dessa classe
- **Página Fluxo**: card "Intangível" **sempre visível** (mesmo saciado, com badge "Dentro do limiar"), âmbar ao estourar. Custo: uma leitura do histórico, fixa — o teto do guard de queries da página subiu de 6 para 7
- **Ritual matinal, passo 5**: banner âmbar **sem botão de dispensar** — a fome só passa concluindo. Com Intangíveis em Pronto, cada um vem com "Puxar" ali mesmo (`pullItem`); sem nenhum, a mensagem muda para "E não há nenhum Intangível em Pronto" e os atalhos apontam para Backlog e Ideias
- **MCP**: `get-pull-queue` publica `context.intangible_hunger` (`days`, `threshold_days`, `starving`, `anchor`, `last_completed_at`, `ready_in_pronto`, `label`). É contexto do **quadro**, não de uma posição — dispara mesmo com a fila sem Intangível. As instruções do servidor descrevem a fome, e um teste impede a divergência
- **Testes**: `IntangibleStarvationTest` (métrica, puxada que não zera, limiar de config, partida a frio, corte que não zera, despensa), `IntangibleStarvationUiTest` (card do Fluxo e banner do ritual, incluindo a ausência de dispensa) e `Mcp/PullQueueToolTest` (costura MCP e instruções)

## Keyboard Shortcuts

| Atalho   | Ação                        |
| -------- | --------------------------- |
| `Ctrl+N` | Nova task (quick-add modal) |
| `Ctrl+B` | Ir para Kanban (Board)      |
| `Ctrl+D` | Ir para o Ritual matinal    |
| `Ctrl+I` | Ir para Inbox               |
| `Ctrl+T` | Start/stop timer            |
| `Esc`    | Fechar modal                |
| `Ctrl+K` | Command Palette             |

Todos os atalhos requerem `Ctrl` (ou `Cmd` no Mac). Ignorar quando foco em `input`/`textarea`/`select`/`[contenteditable]`.

Atalhos de página (registrados no próprio componente, não no layout):

| Atalho       | Página     | Ação                                        |
| ------------ | ---------- | ------------------------------------------- |
| `Ctrl+G`     | `/updates` | Gerar / regerar o rascunho do update        |
| `Ctrl+Enter` | `/updates` | Copiar o rascunho para a área de transferência |

Esses dois valem **também com o foco no editor** — é lá que a mão está. "Marcar como enviado" fica deliberadamente sem atalho: é o ato que escreve o histórico e zera o relógio da cadência, e merece um clique consciente.

## Regras Gerais

- Commits: conventional commits (`feat:`, `fix:`, `chore:`)
- Testes Feature (Pest) para lógica de negócio
- Keyboard-first: todo componente interativo deve ter suporte a atalho
- Empty states com ícone + texto + CTA + dica de atalho
- Deletar sempre com confirmação via `<flux:modal>`

## MCP Server

O SoloBoard expõe um MCP Server para integração com AI clients (Claude Code, Cursor, etc.).

- **Configurar**: `claude mcp add --transport http soloboard https://regnt.sophostech.com.br/mcp`
- **Header**: `Authorization: Bearer {SOLOBOARD_MCP_KEY}` (definido no `.env`)
- **Tools disponíveis**:
  - `list-tasks` — Lista tasks com filtros (project_slug, status, limit)
  - `get-task` — Detalhes completos de uma task (inclui status_timeline, time_in_status, current_status_duration_minutes)
  - `create-task` — Cria nova task (default: inbox/medium)
  - `update-task` — Atualiza task (markAsDone ao mudar para done)
  - `delete-task` — Deleta task e time entries
  - `list-features` — Lista features com filtros (project_slug, status, limit)
  - `get-feature` — Detalhes completos de uma feature (spec, tasks, time entries, progress)
  - `create-feature` — Cria nova feature com spec e prioridade
  - `update-feature` — Atualiza feature (spec, prioridade, due_date, projeto)
  - `delete-feature` — Deleta feature (desvincula tasks, deleta time entries)
  - `add-task-to-feature` — Vincula task existente a uma feature (herda projeto se não tiver)
  - `start-timer` — Inicia timer para task ou feature (para outros automaticamente)
  - `stop-timer` — Para timer com notas opcionais
  - `timer-status` — Mostra timer ativo (task ou feature)
  - `get-pull-queue` — Fila de puxar (Pronto em ordem, com o motivo de cada posição)
  - `get-ritual-status` — Estado do ritual matinal de hoje + snapshot do quadro (leitura)
  - `get-update-queue` — Fila de updates semanais por urgência + contagem de devidos (leitura)
  - `generate-client-update` — Gera e persiste o rascunho do update (mesma janela e template da UI; `force` para descartar edição manual)
  - `mark-update-sent` — Marca um rascunho existente como enviado (exige `draft_id`; zera o relógio da cadência)
  - `list-projects` — Lista projetos por status
- **Resource**: `soloboard://overview` — Resumo geral do estado (inclui active_features)
- **Prompts**:
  - `session-planning` — Lê prompt de uma task e gera contexto para planejar sessão de AI coding
  - `feature-planning` — Lê spec de uma feature e gera contexto para planejar implementação

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
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

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

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

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

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
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues (`rfl-designer/regnt-solo`) via the `gh` CLI; external PRs are not a triage surface. See `docs/agents/issue-tracker.md`.

### Triage labels

Canonical default vocabulary (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context layout — one `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
