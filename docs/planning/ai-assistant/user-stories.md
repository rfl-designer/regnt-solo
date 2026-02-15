---
epic: AI Assistant (Triagem + Priorização)
description: Coach de produtividade com contexto usando API Anthropic
---

## Ordem de Execução

1. US-001 — Configuração e variáveis de ambiente
2. US-002 — AiAssistantService com método callApi
3. US-003 — Método suggestDailyPlan
4. US-004 — Método analyzeBacklog
5. US-005 — Método detectPatterns
6. US-006 — Testes do AiAssistantService
7. US-007 — Botão "Sugerir plano" no Daily Planner
8. US-008 — Modal de sugestões do Daily Planner
9. US-009 — Botão "Analisar backlog" no Inbox
10. US-010 — Sugestões inline no Inbox
11. US-011 — Rate limiting e error handling nas integrações
12. US-012 — Testes de integração AI no Daily Planner e Inbox
13. US-013 — Seção Insights no Dashboard
14. US-014 — Cache de insights e ação "Ignorar"
15. US-015 — Testes de AI Insights no Dashboard

---

# User Stories: AI Assistant

## Visão Geral

O AI Assistant é um coach de produtividade para dev solo integrado ao SoloBoard. Usa a API Anthropic/Claude via HTTP client nativo do Laravel para oferecer 3 funcionalidades: sugestão de plano diário, análise de backlog e detecção de padrões. Toda a feature é controlada por feature flag (`ai_enabled`, default: `false`), garantindo zero impacto quando desabilitada. A implementação segue os padrões existentes: SFC para views, Flux UI Pro para componentes, Pest para testes, e interface em PT-BR com código em inglês.

As 15 User Stories estão organizadas em 3 fases correspondentes às Tasks da Epic:
- **Fase 1 (US-001 a US-006):** Serviço AI + Configuração — fundação backend
- **Fase 2 (US-007 a US-012):** Integração no Daily Planner e Inbox — UI interativa
- **Fase 3 (US-013 a US-015):** Insights proativos no Dashboard — valor passivo

---

## Lista de User Stories

---

### US-001: Configuração AI no config/soloboard.php e variáveis de ambiente

**Como** desenvolvedor do SoloBoard
**Quero** ter as configurações de AI centralizadas em `config/soloboard.php` com variáveis de ambiente correspondentes
**Para** que a feature AI possa ser habilitada/desabilitada e configurada sem alterar código

**Critérios de Aceitação:**
- [ ] `config/soloboard.php` contém as chaves: `ai_enabled` (bool, default `false`), `ai_api_key` (string, nullable), `ai_model` (string, default `claude-sonnet-4-20250514`), `ai_insights_cache_hours` (int, default `24`)
- [ ] Cada chave lê de variável de ambiente: `SOLOBOARD_AI_ENABLED`, `ANTHROPIC_API_KEY`, `SOLOBOARD_AI_MODEL`, `SOLOBOARD_AI_INSIGHTS_CACHE_HOURS`
- [ ] `.env.example` contém as 3 variáveis novas com valores padrão documentados (AI_ENABLED=false, API_KEY vazio, MODEL com default)
- [ ] `.env` é atualizado com as mesmas variáveis
- [ ] `config('soloboard.ai_enabled')` retorna `false` por padrão (sem configuração)
- [ ] A chave `mcp_key` existente permanece inalterada

**Complexidade:** P
**Dependências:** Nenhuma

---

### US-002: Criar AiAssistantService com integração à API Anthropic

**Como** desenvolvedor do SoloBoard
**Quero** um Service `AiAssistantService` registrado no container com método privado `callApi` que se comunica com a API Anthropic
**Para** que os métodos públicos possam reutilizar a lógica de chamada HTTP de forma consistente

**Critérios de Aceitação:**
- [ ] Arquivo `app/Services/AiAssistantService.php` criado com namespace `App\Services`
- [ ] Constructor usa PHP 8 property promotion: `private readonly string $apiKey`, `private readonly string $model`, `private readonly bool $enabled`
- [ ] Método público `isEnabled(): bool` retorna o valor de `$this->enabled`
- [ ] Método privado `callApi(string $systemPrompt, string $userMessage): array` faz POST para `https://api.anthropic.com/v1/messages` com headers `x-api-key`, `anthropic-version: 2023-06-01`
- [ ] `callApi` usa `Http::withHeaders()->timeout(30)->post()` (HTTP client nativo do Laravel)
- [ ] `callApi` envia `model`, `max_tokens: 1024`, `system`, `messages` no body
- [ ] Método privado `parseJsonResponse(string $content): array` extrai JSON da resposta da API com fallback para array vazio se parsing falhar
- [ ] Quando `isEnabled()` retorna `false`, os métodos públicos retornam arrays vazios sem chamar a API
- [ ] Service registrado como singleton no `AppServiceProvider::register()` com binding que lê `config('soloboard.*')`
- [ ] API key NUNCA é exposta como propriedade pública (é `private readonly`)
- [ ] Todos os métodos têm PHPDoc blocks com `@return` tipado

**Complexidade:** M
**Dependências:** US-001

---

### US-003: Método suggestDailyPlan no AiAssistantService

**Como** usuário do SoloBoard
**Quero** que o AI analise minhas tasks disponíveis e histórico de conclusão para sugerir um plano diário
**Para** que eu comece o dia com foco nas tasks mais relevantes

**Critérios de Aceitação:**
- [ ] Método público `suggestDailyPlan(Collection $tasks, array $history): array` implementado
- [ ] System prompt contextualiza o AI como "productivity coach for a solo developer" e instrui formato JSON de resposta
- [ ] User message inclui: lista de tasks (id, title, status, priority, due_date, estimated_minutes, project name) e histórico (completionRate dos últimos dias)
- [ ] Retorna array com chave `suggestions`, cada item contendo: `task_id` (int), `reason` (string em PT-BR), `order` (int)
- [ ] Quando `isEnabled()` é `false`, retorna `['suggestions' => []]`
- [ ] Quando a API falha (timeout, erro HTTP, JSON inválido), retorna `['suggestions' => []]` sem lançar exceção
- [ ] Método privado `buildDailyPlanPrompt(Collection $tasks, array $history): array` monta system prompt e user message

**Complexidade:** M
**Dependências:** US-002

---

### US-004: Método analyzeBacklog no AiAssistantService

**Como** usuário do SoloBoard
**Quero** que o AI analise meu backlog/inbox e sugira ações concretas por task
**Para** que eu mantenha meu backlog organizado sem gastar tempo triando manualmente

**Critérios de Aceitação:**
- [ ] Método público `analyzeBacklog(Collection $tasks): array` implementado
- [ ] System prompt instrui o AI a analisar tasks e sugerir ações: `prioritize`, `archive`, `estimate`
- [ ] User message inclui: lista de tasks inbox/backlog (id, title, priority, due_date, created_at, project name, days_since_creation)
- [ ] Retorna array com chave `analysis`, cada item contendo: `task_id` (int), `action` (string: prioritize|archive|estimate), `reason` (string em PT-BR), e campos opcionais `suggested_priority`, `suggested_project`, `estimated_minutes`
- [ ] Quando `isEnabled()` é `false`, retorna `['analysis' => []]`
- [ ] Quando a API falha, retorna `['analysis' => []]` sem lançar exceção
- [ ] Método privado `buildBacklogPrompt(Collection $tasks): array` monta system prompt e user message

**Complexidade:** M
**Dependências:** US-002

---

### US-005: Método detectPatterns no AiAssistantService

**Como** usuário do SoloBoard
**Quero** que o AI detecte padrões negativos na minha produtividade (projetos abandonados, over-commitment, falta de deep work)
**Para** que eu receba alertas proativos antes que problemas se agravem

**Critérios de Aceitação:**
- [ ] Método público `detectPatterns(array $weeklyData): array` implementado
- [ ] System prompt instrui o AI a detectar padrões: projetos sem atividade, over-commitment, falta de deep work, tasks stale
- [ ] User message inclui: projetos com última atividade, tasks por status, horas de deep work por dia, completionRate semanal, tasks stale
- [ ] Retorna array com chave `insights`, cada item contendo: `type` (string), `severity` (warning|info), `message` (string em PT-BR), `action_label` (string em PT-BR), `action_route` (string), `action_params` (array)
- [ ] Quando `isEnabled()` é `false`, retorna `['insights' => []]`
- [ ] Quando a API falha, retorna `['insights' => []]` sem lançar exceção
- [ ] Método privado `buildPatternsPrompt(array $weeklyData): array` monta system prompt e user message

**Complexidade:** M
**Dependências:** US-002

---

### US-006: Testes Feature do AiAssistantService

**Como** desenvolvedor do SoloBoard
**Quero** testes Feature completos para o AiAssistantService com mock HTTP
**Para** garantir que o serviço funciona corretamente sem depender da API real

**Critérios de Aceitação:**
- [ ] Arquivo `tests/Feature/AiAssistantTest.php` criado com Pest
- [ ] Teste: `isEnabled()` retorna `false` quando `ai_enabled=false` na config
- [ ] Teste: `isEnabled()` retorna `true` quando `ai_enabled=true` na config
- [ ] Teste: `suggestDailyPlan` retorna array vazio quando desabilitado (sem chamada HTTP)
- [ ] Teste: `analyzeBacklog` retorna array vazio quando desabilitado (sem chamada HTTP)
- [ ] Teste: `detectPatterns` retorna array vazio quando desabilitado (sem chamada HTTP)
- [ ] Teste: `suggestDailyPlan` chama API e retorna sugestões parseadas (com `Http::fake()`)
- [ ] Teste: `analyzeBacklog` chama API e retorna análise parseada (com `Http::fake()`)
- [ ] Teste: `detectPatterns` chama API e retorna insights parseados (com `Http::fake()`)
- [ ] Teste: `suggestDailyPlan` retorna array vazio quando API retorna erro HTTP (500, timeout)
- [ ] Teste: `analyzeBacklog` retorna array vazio quando API retorna JSON malformado
- [ ] Teste: API é chamada com headers corretos (`x-api-key`, `anthropic-version`)
- [ ] Teste: API é chamada com model correto da config
- [ ] Padrão Pest: `beforeEach` com setup, `Http::fake()` para mock, assertions claras
- [ ] Todos os testes passam com `php artisan test --compact --filter=AiAssistant`

**Complexidade:** M
**Dependências:** US-003, US-004, US-005

---

### US-007: Botão "Sugerir plano" no Daily Planner

**Como** usuário do SoloBoard
**Quero** ver um botão "Sugerir plano" no Daily Planner quando AI está habilitada
**Para** que eu possa solicitar sugestões do AI com um clique

**Critérios de Aceitação:**
- [ ] Botão "✨ Sugerir plano" aparece no header do Daily Planner (ao lado dos controles existentes)
- [ ] Botão usa componente `<flux:button>` com ícone `sparkles` e variante adequada
- [ ] Botão só é renderizado quando `config('soloboard.ai_enabled')` é `true`
- [ ] Quando AI está desabilitada, nenhum elemento AI é renderizado (zero impacto no DOM)
- [ ] Ao clicar, chama action Livewire `suggestPlan()` no componente SFC
- [ ] Action `suggestPlan()` seta `$aiLoading = true`, chama `AiAssistantService::suggestDailyPlan()`, armazena resultado em `$aiSuggestions`, seta `$aiLoading = false`
- [ ] Propriedade `$aiLoading` (bool, default `false`) controla estado de loading
- [ ] Propriedade `$aiSuggestions` (array, default `[]`) armazena sugestões retornadas
- [ ] Durante loading, botão mostra estado desabilitado com `wire:loading` ou `$aiLoading`
- [ ] Service é injetado via `app(AiAssistantService::class)` dentro da action (não como propriedade pública)

**Complexidade:** M
**Dependências:** US-003

---

### US-008: Modal de sugestões do Daily Planner

**Como** usuário do SoloBoard
**Quero** ver as sugestões do AI em um modal com opções de adicionar tasks ao plano
**Para** que eu possa revisar e aceitar sugestões de forma prática

**Critérios de Aceitação:**
- [ ] Modal `<flux:modal>` abre automaticamente quando `$aiSuggestions` tem itens
- [ ] Modal tem título "Sugestões do AI" e lista cada sugestão com: nome da task, razão da sugestão (texto em PT-BR), botão "Adicionar ao plano"
- [ ] Cada sugestão mostra a prioridade e projeto da task (se houver) com `<flux:badge>`
- [ ] Botão "Adicionar todas" no footer do modal adiciona todas as tasks sugeridas ao plano de uma vez
- [ ] Ao clicar "Adicionar ao plano" em uma sugestão individual, chama `addToPlan($taskId)` (método já existente no componente)
- [ ] Sugestão adicionada é removida da lista de sugestões (feedback visual imediato)
- [ ] Ao adicionar todas, modal fecha e mostra toast de sucesso via `Flux::toast()`
- [ ] Se `$aiSuggestions` está vazio após chamada (AI não sugeriu nada), mostra toast informativo "Nenhuma sugestão disponível"
- [ ] Loading state: enquanto `$aiLoading` é `true`, mostra `<flux:skeleton>` no lugar do conteúdo do modal
- [ ] Modal pode ser fechado manualmente sem aplicar sugestões

**Complexidade:** M
**Dependências:** US-007

---

### US-009: Botão "Analisar backlog" no Inbox

**Como** usuário do SoloBoard
**Quero** ver um botão "Analisar backlog" no Inbox quando AI está habilitada
**Para** que eu possa solicitar análise inteligente das minhas tasks inbox

**Critérios de Aceitação:**
- [ ] Botão "✨ Analisar backlog" aparece no header do Inbox (ao lado dos controles existentes)
- [ ] Botão usa componente `<flux:button>` com ícone `sparkles` e variante adequada
- [ ] Botão só é renderizado quando `config('soloboard.ai_enabled')` é `true`
- [ ] Quando AI está desabilitada, nenhum elemento AI é renderizado
- [ ] Ao clicar, chama action Livewire `analyzeBacklog()` no componente SFC
- [ ] Action `analyzeBacklog()` seta `$aiLoading = true`, chama `AiAssistantService::analyzeBacklog()` com tasks inbox, armazena resultado em `$aiAnalysis`, seta `$aiLoading = false`
- [ ] Propriedade `$aiLoading` (bool, default `false`) controla estado de loading
- [ ] Propriedade `$aiAnalysis` (array, default `[]`) armazena análise retornada
- [ ] Durante loading, botão mostra estado desabilitado
- [ ] Service é injetado via `app(AiAssistantService::class)` dentro da action

**Complexidade:** M
**Dependências:** US-004

---

### US-010: Sugestões inline no Inbox

**Como** usuário do SoloBoard
**Quero** ver sugestões do AI exibidas inline junto a cada task no Inbox com botões de ação
**Para** que eu possa aplicar sugestões diretamente sem sair do contexto

**Critérios de Aceitação:**
- [ ] Quando `$aiAnalysis` tem itens, cada task no Inbox que possui sugestão mostra um banner/callout abaixo dela
- [ ] Banner usa `<flux:callout>` ou card estilizado com cores do dark mode (`zinc-800/50`, `border-zinc-700`)
- [ ] Sugestão de priorizar mostra: "Sugerir prioridade: {priority} — {reason}" com botão "Aplicar"
- [ ] Sugestão de arquivar mostra: "Sugerir arquivar — {reason}" com botão "Arquivar"
- [ ] Sugestão de estimar mostra: "Estimativa sugerida: {minutes}min — {reason}" com botão "Aplicar"
- [ ] Botão "Aplicar" em sugestão de prioridade atualiza a prioridade da task e remove a sugestão da lista
- [ ] Botão "Arquivar" move a task para status Backlog (ou deleta, conforme ação) e remove da lista
- [ ] Botão "Aplicar" em estimativa atualiza `estimated_minutes` da task e remove a sugestão
- [ ] Após aplicar sugestão, `unset($this->tasks)` para invalidar computed e mostra toast de sucesso
- [ ] Botão "Ignorar" por sugestão remove apenas aquela sugestão da lista sem aplicar ação
- [ ] Se nenhuma task tem sugestão (AI retornou vazio), mostra toast "Nenhuma sugestão para o backlog atual"

**Complexidade:** G
**Dependências:** US-009

---

### US-011: Rate limiting e error handling nas integrações AI

**Como** usuário do SoloBoard
**Quero** que as chamadas AI tenham rate limiting e tratamento de erros gracioso
**Para** que eu não gaste créditos excessivos da API e a aplicação nunca quebre por falha do AI

**Critérios de Aceitação:**
- [ ] Rate limit de 1 chamada por minuto por feature (chaves separadas: `ai_rate_limit:daily_planner`, `ai_rate_limit:inbox`)
- [ ] Rate limit usa `Cache::has()` / `Cache::put()` com TTL de 60 segundos
- [ ] Quando rate limit é atingido, mostra toast de aviso: "Aguarde 1 minuto entre chamadas" via `Flux::toast(variant: 'warning', ...)`
- [ ] Quando rate limit é atingido, não chama a API e não altera `$aiLoading`
- [ ] Quando API retorna erro HTTP (4xx, 5xx), mostra toast de erro: "Erro ao consultar AI. Tente novamente." via `Flux::toast(variant: 'danger', ...)`
- [ ] Quando API dá timeout (>30s), mostra toast de erro sem crash
- [ ] Quando JSON da resposta é malformado, mostra toast de erro sem crash
- [ ] Em todos os cenários de erro, `$aiLoading` volta para `false`
- [ ] Em todos os cenários de erro, `$aiSuggestions` / `$aiAnalysis` permanece como array vazio
- [ ] Nenhum erro do AI impede o uso normal das páginas (Daily Planner e Inbox continuam funcionais)

**Complexidade:** M
**Dependências:** US-007, US-009

---

### US-012: Testes de integração AI no Daily Planner e Inbox

**Como** desenvolvedor do SoloBoard
**Quero** testes Feature que verificam a integração do AI nos componentes Livewire
**Para** garantir que botões, modals, sugestões e error handling funcionam corretamente

**Critérios de Aceitação:**
- [ ] Arquivo `tests/Feature/AiIntegrationTest.php` criado com Pest
- [ ] Teste: botão "Sugerir plano" NÃO aparece quando `ai_enabled=false`
- [ ] Teste: botão "Sugerir plano" aparece quando `ai_enabled=true`
- [ ] Teste: action `suggestPlan` chama o Service e popula `$aiSuggestions` (mock do Service)
- [ ] Teste: sugestões são exibidas no componente após chamada
- [ ] Teste: "Adicionar ao plano" adiciona task ao DailyPlan
- [ ] Teste: botão "Analisar backlog" NÃO aparece quando `ai_enabled=false`
- [ ] Teste: botão "Analisar backlog" aparece quando `ai_enabled=true`
- [ ] Teste: action `analyzeBacklog` chama o Service e popula `$aiAnalysis` (mock do Service)
- [ ] Teste: sugestões inline são exibidas por task
- [ ] Teste: "Aplicar" sugestão de prioridade atualiza a task no banco
- [ ] Teste: rate limit impede segunda chamada dentro de 1 minuto
- [ ] Teste: erro do Service mostra toast e não quebra componente
- [ ] Padrão Pest: `beforeEach` com `actingAs`, mock do `AiAssistantService`, `Livewire::test()`
- [ ] Todos os testes passam com `php artisan test --compact --filter=AiIntegration`

**Complexidade:** G
**Dependências:** US-008, US-010, US-011

---

### US-013: Seção Insights proativa no Dashboard

**Como** usuário do SoloBoard
**Quero** ver uma seção "Insights" no Dashboard com alertas proativos sobre minha produtividade
**Para** que eu receba feedback do AI sem precisar solicitar ativamente

**Critérios de Aceitação:**
- [ ] Nova seção "Insights" renderizada no Dashboard, acima da seção "Tempo médio por status"
- [ ] Seção só é renderizada quando `config('soloboard.ai_enabled')` é `true`
- [ ] Seção tem heading "✨ Insights" com ícone `sparkles`
- [ ] Cada insight é renderizado como card/banner com: ícone de severidade (warning = amarelo, info = azul), mensagem em PT-BR, botões de ação
- [ ] Cada insight tem até 2 botões: ação principal (ex: "Ver projeto", "Abrir inbox") e "Ignorar"
- [ ] Botão de ação principal navega para a rota correta usando `action_route` e `action_params` do insight
- [ ] Quando não há insights (array vazio), a seção não é renderizada (sem espaço vazio)
- [ ] Estilização segue dark mode: `border-zinc-700`, `bg-zinc-800/50`, cores de severidade com opacidade
- [ ] Componente carrega insights via computed property `$this->insights` que chama o Service
- [ ] Botão "Atualizar" permite forçar refresh dos insights (limpa cache e rechama)

**Complexidade:** G
**Dependências:** US-005

---

### US-014: Cache de insights e ação "Ignorar"

**Como** usuário do SoloBoard
**Quero** que os insights sejam cacheados por 24h e que eu possa ignorar insights irrelevantes por 7 dias
**Para** que a API não seja chamada excessivamente e eu não veja alertas repetitivos

**Critérios de Aceitação:**
- [ ] Insights são cacheados com `Cache::remember()` usando chave `ai_insights:{date}` e TTL de `config('soloboard.ai_insights_cache_hours')` horas
- [ ] Cache key inclui a data do dia (`now()->toDateString()`) para renovar diariamente
- [ ] Computed property `$this->insights` lê do cache antes de chamar a API
- [ ] Ao clicar "Ignorar" em um insight, chama action `dismissInsight(string $insightHash)`
- [ ] `dismissInsight` salva no cache: `Cache::put("ai_insight_dismissed:{hash}", true, now()->addDays(7))`
- [ ] Hash do insight é calculado com `md5($insight['message'])`
- [ ] Insights ignorados são filtrados da lista antes de renderizar: `collect($insights)->reject(fn ($i) => Cache::has(...))`
- [ ] Após ignorar, insight desaparece imediatamente da UI (unset computed)
- [ ] Botão "Atualizar" no header da seção limpa o cache (`Cache::forget("ai_insights:{date}")`) e recarrega
- [ ] Após 7 dias, insight ignorado volta a aparecer se ainda for relevante
- [ ] Quando todos os insights são ignorados, a seção desaparece

**Complexidade:** M
**Dependências:** US-013

---

### US-015: Testes de AI Insights no Dashboard

**Como** desenvolvedor do SoloBoard
**Quero** testes Feature que verificam a seção Insights no Dashboard
**Para** garantir que cache, ações e "Ignorar" funcionam corretamente

**Critérios de Aceitação:**
- [ ] Arquivo `tests/Feature/AiInsightsTest.php` criado com Pest
- [ ] Teste: seção Insights NÃO aparece quando `ai_enabled=false`
- [ ] Teste: seção Insights aparece quando `ai_enabled=true` e há insights
- [ ] Teste: seção Insights NÃO aparece quando `ai_enabled=true` mas insights é array vazio
- [ ] Teste: insights são renderizados com mensagem e botões de ação
- [ ] Teste: "Ignorar" remove insight da lista e salva no cache por 7 dias
- [ ] Teste: insight ignorado não aparece na próxima renderização
- [ ] Teste: cache de insights é usado (Service não é chamado na segunda renderização)
- [ ] Teste: botão "Atualizar" limpa cache e rechama o Service
- [ ] Teste: ação principal do insight gera link correto para a rota
- [ ] Padrão Pest: `beforeEach` com `actingAs`, mock do `AiAssistantService`, `Livewire::test('pages::dashboard')`
- [ ] Todos os testes passam com `php artisan test --compact --filter=AiInsights`

**Complexidade:** M
**Dependências:** US-013, US-014

---

## Resumo por Fase

### Fase 1 — Serviço AI + Configuração (US-001 a US-006)

| US | Título | Complexidade | Tipo |
|----|--------|-------------|------|
| US-001 | Configuração AI e variáveis de ambiente | P | core |
| US-002 | AiAssistantService com callApi | M | core |
| US-003 | Método suggestDailyPlan | M | core |
| US-004 | Método analyzeBacklog | M | core |
| US-005 | Método detectPatterns | M | core |
| US-006 | Testes do AiAssistantService | M | core |

### Fase 2 — AI no Daily Planner + Inbox (US-007 a US-012)

| US | Título | Complexidade | Tipo |
|----|--------|-------------|------|
| US-007 | Botão "Sugerir plano" no Daily Planner | M | both |
| US-008 | Modal de sugestões do Daily Planner | M | both |
| US-009 | Botão "Analisar backlog" no Inbox | M | both |
| US-010 | Sugestões inline no Inbox | G | both |
| US-011 | Rate limiting e error handling | M | both |
| US-012 | Testes de integração AI | G | core |

### Fase 3 — AI Insights no Dashboard (US-013 a US-015)

| US | Título | Complexidade | Tipo |
|----|--------|-------------|------|
| US-013 | Seção Insights no Dashboard | G | both |
| US-014 | Cache de insights e "Ignorar" | M | both |
| US-015 | Testes de AI Insights | M | core |

---

## Diagrama de Dependências

```
US-001 (Config)
  └── US-002 (Service base + callApi)
        ├── US-003 (suggestDailyPlan)
        │     └── US-007 (Botão Daily Planner)
        │           └── US-008 (Modal sugestões)
        │                 └── US-012 (Testes integração) ←─┐
        ├── US-004 (analyzeBacklog)                        │
        │     └── US-009 (Botão Inbox)                     │
        │           └── US-010 (Sugestões inline)           │
        │                 └── US-012 ──────────────────────┘
        └── US-005 (detectPatterns)
              └── US-013 (Seção Insights Dashboard)
                    └── US-014 (Cache + Ignorar)
                          └── US-015 (Testes Insights)

US-003 + US-004 + US-005 → US-006 (Testes Service)
US-007 + US-009 → US-011 (Rate limit + Error handling)
US-011 → US-012 (Testes integração)
```
