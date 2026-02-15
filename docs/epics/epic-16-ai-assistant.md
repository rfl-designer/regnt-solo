# EPIC 16: AI Assistant (Triagem + Priorização)

> **Sessão Claude Code:** Coach de produtividade com contexto.
> **Prioridade:** #7 (alto impacto, alto esforço)
> **Estimativa:** ~1.5h · 3 tasks
> **Dependência:** Epics 10-15 (dados ricos alimentam sugestões melhores)

---

## Contexto

Diferente do Linear Triage Intelligence (para times processarem issues externas), o SoloBoard AI é um coach para o dev solo refletir sobre suas próprias tasks. Usa a API do Claude para sugerir priorização, estimar esforço e detectar padrões.

---

## Task 16.1 — Serviço AI + Configuração

```yaml
Prompt: |
    Crie o serviço de AI para o SoloBoard:

    config/soloboard.php (atualizar):
    - ai_enabled => env('SOLOBOARD_AI_ENABLED', false)
    - ai_api_key => env('ANTHROPIC_API_KEY')
    - ai_model => env('SOLOBOARD_AI_MODEL', 'claude-sonnet-4-20250514')

    app/Services/AiAssistantService.php:
    - Usa HTTP client do Laravel para chamar API Anthropic
    - Método: suggestDailyPlan($tasks, $history): array
      - Recebe tasks disponíveis + histórico de completionRate
      - Retorna lista ordenada de sugestões com razão
    - Método: analyzeBacklog($tasks): array
      - Analisa tasks no backlog/inbox
      - Sugere: arquivar, priorizar, estimar tempo
    - Método: detectPatterns($weeklyData): array
      - Detecta padrões: projetos abandonados, over-commitment, melhores horários
    - Cada método monta system prompt contextualizado com dados do SoloBoard

    Prompt engineering:
    - System prompt: "You are a productivity coach for a solo developer..."
    - Contexto inclui: projetos ativos, tasks por status, horas recentes, padrões
    - Response format: JSON estruturado

Acceptance Criteria:
    - Serviço configura corretamente quando ai_enabled=true
    - Gracefully disabled quando ai_enabled=false
    - suggestDailyPlan retorna sugestões com razões
    - analyzeBacklog identifica tasks para ação
    - Teste Feature (com mock): verificar chamadas e parsing

Arquivos:
    - app/Services/AiAssistantService.php
    - config/soloboard.php (atualizar)
    - .env, .env.example (ANTHROPIC_API_KEY, SOLOBOARD_AI_ENABLED)
    - tests/Feature/AiAssistantTest.php

Commit: "feat: AI assistant service with Anthropic API integration"
```

---

## Task 16.2 — AI no Daily Planner + Inbox

```yaml
Prompt: |
    Integre o AI Assistant nas páginas:

    1. Daily Planner (⚡daily-planner.blade.php):
    - Botão "✨ Sugerir plano" (só aparece se ai_enabled)
    - Ao clicar: chama suggestDailyPlan() via Livewire
    - Mostra sugestões em modal/drawer:
      - Lista de tasks sugeridas com razão ("Alta prioridade, vencendo amanhã")
      - Botão "Adicionar ao plano" por sugestão
      - Botão "Adicionar todas"
    - Loading state com <flux:skeleton>

    2. Inbox (⚡inbox.blade.php):
    - Botão "✨ Analisar backlog" (só aparece se ai_enabled)
    - Ao clicar: chama analyzeBacklog()
    - Mostra sugestões por task:
      - "Sugerir prioridade: high (motivo: vencendo em 2 dias)"
      - "Sugerir projeto: API Gateway (motivo: parece relacionado)"
      - "Sugerir arquivar (motivo: criada há 30 dias, sem atividade)"
    - Botões "Aplicar" por sugestão

    3. Ambas as integrações:
    - Só renderizam se config('soloboard.ai_enabled')
    - Fallback gracioso se API falha (toast de erro, sem crash)
    - Rate limit básico: máx 1 chamada por minuto por feature

Acceptance Criteria:
    - Botões AI só aparecem se ai_enabled=true
    - Sugestões do planner são acionáveis
    - Análise do backlog sugere ações concretas
    - Erro da API mostra toast, não crash
    - Funciona perfeitamente sem AI (feature flag off)
    - Teste Feature: mock de API, verificar UI

Arquivos:
    - resources/views/pages/⚡daily-planner.blade.php (atualizar)
    - resources/views/pages/⚡inbox.blade.php (atualizar)
    - tests/Feature/AiIntegrationTest.php

Commit: "feat: AI-powered suggestions in Daily Planner and Inbox"
```

---

## Task 16.3 — AI Insights no Dashboard

```yaml
Prompt: |
  Adicione insights proativos no Dashboard:

  No Dashboard (⚡dashboard.blade.php):
  - Nova seção "Insights" (só se ai_enabled):
  - Banner proativo quando detecta padrões negativos:
    - "Projeto Mobile App sem atividade há 14 dias. Risco de abandono?"
    - "12 tasks em backlog há +14 dias. Revisar?"
    - "Seus últimos 5 dias tiveram 0h de deep work. Tudo bem?"
  - Cada insight com ações: "Ver projeto", "Abrir inbox", "Ignorar"
  - Cache de insights: gerar no máximo 1x por dia (cache em DB ou file)
  - Método detectPatterns() do AiAssistantService

  Atualizar config/soloboard.php:
  - ai_insights_cache_hours => 24

Acceptance Criteria:
  - Insights aparecem no dashboard quando relevantes
  - Cache previne chamadas excessivas à API
  - Ações nos insights navegam para páginas corretas
  - "Ignorar" esconde o insight por 7 dias
  - Sem insights quando ai_enabled=false
  - Teste Feature: mock patterns, verificar insights

Arquivos:
  - resources/views/pages/⚡dashboard.blade.php (atualizar)
  - app/Services/AiAssistantService.php (atualizar)
  - config/soloboard.php (atualizar)
  - tests/Feature/AiInsightsTest.php

Commit: "feat: proactive AI insights on Dashboard with caching"
```
