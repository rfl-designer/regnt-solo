# EPIC 14: Focus Mode / Deep Work Timer

> **Sessão Claude Code:** Medir e incentivar sessões de trabalho focado.
> **Prioridade:** #6 (diferenciação, esforço médio)
> **Estimativa:** ~45min · 2 tasks
> **Dependência:** Epic 10 (MCP) — start_timer via MCP pode iniciar focus sessions

---

## Contexto

Combinar time tracking existente com conceito de "sessão de foco" mensurável. Nova métrica: "horas de deep work" vs. "horas de timer" (nem todo timer é foco). Streaks de foco criam hábito.

---

## Task 14.1 — Focus Session: Model + Timer integration

```yaml
Prompt: |
    Estenda o TimeEntry para suportar sessões de foco:

    Migration: add_focus_fields_to_time_entries
    - is_focus_session (boolean default false)
    - focus_rating (integer nullable, 1-5) — rating de produtividade ao encerrar

    Atualizar TimeEntry model:
    - Scope: focusSessions(), focusForDate($date), focusForWeek()
    - focusDurationMinutes(): soma de minutos das focus sessions

    Atualizar Task model:
    - totalFocusMinutes(): soma de focus sessions da task

    Atualizar Timer SFC (⚡timer.blade.php):
    - Novo botão "Modo Foco" (ícone target/crosshair) ao lado do start normal
    - Ao iniciar focus: is_focus_session=true na TimeEntry
    - Visual diferente: borda/badge amarelo/dourado pulsante durante focus

    Atualizar Timer Notes Modal (⚡timer-notes-modal.blade.php):
    - Se era focus session: adicionar rating 1-5 (estrelas ou ícones)
      "Como foi a sessão?" antes do campo de notas
    - Rating salvo no focus_rating da TimeEntry

    Atualizar Global Timer (⚡global-timer.blade.php):
    - Se focus: "🎯 [task.title] HH:MM:SS" (ícone diferente do timer normal)

    MCP: Atualizar StartTimerTool
    - Novo input opcional: focus (boolean, default false)
    - Se focus=true, cria TimeEntry com is_focus_session=true

Acceptance Criteria:
    - Focus session cria TimeEntry com is_focus_session=true
    - Visual diferente no timer (focus vs normal)
    - Rating aparece ao parar focus session
    - MCP suporta iniciar focus session
    - Scopes de focus funcionam
    - Teste Feature: start focus, stop com rating, verificar DB

Arquivos:
    - database/migrations/*_add_focus_fields_to_time_entries_table.php
    - app/Models/TimeEntry.php (atualizar)
    - app/Models/Task.php (atualizar)
    - resources/views/components/⚡timer.blade.php (atualizar)
    - resources/views/components/⚡timer-notes-modal.blade.php (atualizar)
    - resources/views/components/⚡global-timer.blade.php (atualizar)
    - app/Mcp/Tools/StartTimerTool.php (atualizar)
    - tests/Feature/FocusSessionTest.php

Commit: "feat: focus session support with rating and MCP integration"
```

---

## Task 14.2 — Focus Metrics no Dashboard + Weekly Review

```yaml
Prompt: |
    Adicione métricas de Deep Work:

    1. No Dashboard (⚡dashboard.blade.php):
    - Novo metric card: "Deep Work hoje" (icon: target, cor amber)
      → Click navega para /time?period=today&focus=1
    - No chart de horas por projeto: diferenciar horas normais vs focus
      (barras empilhadas ou cores diferentes)

    2. No Weekly Review (⚡weekly-review.blade.php):
    - Nova seção "Deep Work":
      - Total de horas de focus na semana
      - Focus ratio: % de tempo em deep work vs. tempo total tracked
      - Rating médio das sessões
      - Streak: dias consecutivos com +2h de deep work (últimas 4 semanas)

    3. No Time Report (⚡time-report.blade.php):
    - Novo filtro toggle: "Apenas Focus" para filtrar focus sessions
    - Coluna adicional na tabela: badge "Focus" quando is_focus_session

    4. No Task Modal (⚡task-modal.blade.php):
    - Na lista de TimeEntries: badge "🎯" para focus sessions
    - Rating exibido (estrelas) quando disponível

Acceptance Criteria:
    - Dashboard mostra deep work hoje
    - Weekly Review calcula focus ratio e streak
    - Time Report filtra por focus
    - Task Modal mostra badge focus
    - Teste Feature: criar focus sessions, verificar métricas

Arquivos:
    - resources/views/pages/⚡dashboard.blade.php (atualizar)
    - resources/views/pages/⚡weekly-review.blade.php (atualizar)
    - resources/views/pages/⚡time-report.blade.php (atualizar)
    - resources/views/components/⚡task-modal.blade.php (atualizar)
    - tests/Feature/FocusMetricsTest.php

Commit: "feat: focus metrics in Dashboard, Weekly Review, and Time Report"
```
