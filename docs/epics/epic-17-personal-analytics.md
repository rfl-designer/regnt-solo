# EPIC 17: Personal Analytics Dashboard (Avançado)

> **Sessão Claude Code:** Analytics profundas de produtividade pessoal.
> **Prioridade:** #8 (diferenciação, alto esforço)
> **Estimativa:** ~1.5h · 3 tasks
> **Dependência:** Epics 10-15 (dados ricos necessários)

---

## Contexto

Analytics pessoais que vão além de "horas por projeto". Inspirado no GitHub Contribution Graph + Cycle Time do Linear, mas para um solo dev. Métricas únicas: heatmap de atividade, cycle time pessoal, focus ratio, project health score, velocity trend, streaks.

---

## Task 17.1 — Analytics Service + Cálculos

```yaml
Prompt: |
    Crie serviço de analytics pessoais:

    app/Services/AnalyticsService.php:

    Métodos (cada um retorna dados prontos para charts):

    - contributionHeatmap(int $weeks = 12): array
      Grid de atividade (como GitHub): baseado em horas tracked + tasks completadas
      Retorna: array de {date, level: 0-4, hours, tasks_completed}

    - cycleTime(string $period = '30d', ?int $projectId = null): array
      Tempo médio de backlog→done por projeto (trend chart)
      Retorna: array de {date, avg_minutes, project_name}

    - focusRatio(string $period = '7d'): float
      % de tempo em deep work vs. tempo total tracked

    - projectHealthScores(): array
      Combina: tasks paradas, overdue ratio, atividade recente
      Retorna: array de {project, score: 0-100, factors[]}
      Score = 100 - (stale_penalty + overdue_penalty + inactivity_penalty)

    - velocityTrend(int $weeks = 12): array
      Tasks completadas por semana
      Retorna: array de {week_start, completed_count, created_count}

    - streaks(): array
      - current_streak: dias consecutivos com atividade
      - best_streak: recorde pessoal
      - focus_streak: dias consecutivos com +2h de deep work
      - Retorna: {current, best, focus_current, focus_best}

    - productivityPatterns(int $weeks = 4): array
      Agrega horas por dia da semana e por hora do dia
      Retorna: {best_days: [], best_hours: [], avg_by_day: {}, avg_by_hour: {}}

Acceptance Criteria:
    - Todos os métodos calculam corretamente
    - Performance aceitável (queries otimizadas, sem N+1)
    - Cycle time usa TaskStatusChange (Epic 11)
    - Focus ratio usa is_focus_session (Epic 15)
    - Teste Feature: criar dados variados, verificar cálculos

Arquivos:
    - app/Services/AnalyticsService.php
    - tests/Feature/AnalyticsServiceTest.php

Commit: "feat: personal analytics service with heatmap, cycle time, velocity, and streaks"
```

---

## Task 17.2 — Página SFC: Analytics Dashboard

```yaml
Prompt: |
    Crie o SFC resources/views/pages/⚡analytics.blade.php:

    Header:
    - Título: "Analytics Pessoais"
    - Período seletor: 4 semanas / 12 semanas / 6 meses

    Seção 1 "Atividade":
    - Contribution Heatmap (grid SVG/CSS como GitHub)
    - Legenda: menos → mais ativo
    - Hover mostra: "DD/MM: Xh tracked, Y tasks"

    Seção 2 "Streaks" (cards):
    - Streak atual: "X dias consecutivos 🔥"
    - Recorde pessoal: "Y dias"
    - Focus streak: "Z dias com +2h deep work 🎯"
    - Badges especiais: 7 dias, 14 dias, 30 dias

    Seção 3 "Velocity":
    - <flux:chart.line>: tasks completadas vs criadas por semana
    - Duas linhas: completadas (verde) e criadas (azul)

    Seção 4 "Cycle Time":
    - <flux:chart.bar>: tempo médio por coluna (últimas 4 semanas)
    - Filtro por projeto

    Seção 5 "Saúde dos Projetos":
    - Grid de cards por projeto com health score (0-100)
    - Cores: verde (>70), amarelo (40-70), vermelho (<40)
    - Fatores detalhados em tooltip

    Seção 6 "Padrões":
    - Heatmap de produtividade: dias da semana × horas do dia
    - Destaque: "Você é mais produtivo terça e quinta, entre 9h-12h"

    Adicionar na sidebar:
    - Item "Analytics" (icon: chart-bar) após "Review"

    Rota: Route::livewire('/analytics', 'pages.analytics')->name('analytics')->middleware('auth');

Acceptance Criteria:
    - Heatmap renderiza com dados corretos
    - Streaks calculam corretamente
    - Charts velocity e cycle time renderizam
    - Health scores por projeto com cores
    - Padrões de produtividade identificados
    - Sidebar atualizada
    - Teste Feature: verificar dados na página

Arquivos:
    - resources/views/pages/⚡analytics.blade.php
    - resources/views/layouts/app.blade.php (sidebar)
    - routes/web.php
    - tests/Feature/AnalyticsPageTest.php

Commit: "feat: Personal Analytics dashboard with heatmap, velocity, and health scores"
```

---

## Task 17.3 — Analytics no MCP + Seed

```yaml
Prompt: |
    Integre analytics ao MCP e atualize o seed:

    1. MCP Tool: GetAnalyticsTool (get-analytics)
    - Input: period (string, default '7d'), metric (string, optional)
    - Se metric especificada: retorna apenas essa métrica
    - Se não: retorna resumo com streaks, focus_ratio, velocity_last_week, health_scores
    - Annotation: #[IsReadOnly]

    2. Atualizar DatabaseSeeder para dados ricos:
    - TimeEntries distribuídas ao longo de 30 dias (variando 1-5h/dia)
    - Focus sessions em ~40% dos dias
    - TaskStatusChanges retroativos
    - TaskCommits para algumas tasks done
    - 2 WeeklyReviews com reflexões
    - Dados suficientes para todos os analytics serem interessantes

    3. Registrar GetAnalyticsTool no SoloBoardServer

Acceptance Criteria:
    - MCP retorna analytics corretos
    - Seed gera dados ricos para 30 dias
    - Analytics dashboard mostra dados não-triviais com seed
    - Teste Feature: MCP analytics tool

Arquivos:
    - app/Mcp/Tools/GetAnalyticsTool.php
    - app/Mcp/Servers/SoloBoardServer.php (atualizar)
    - database/seeders/DatabaseSeeder.php (atualizar)
    - database/factories/* (atualizar)
    - tests/Feature/Mcp/AnalyticsToolTest.php

Commit: "feat: MCP analytics tool and rich seed data for 30 days"
```
