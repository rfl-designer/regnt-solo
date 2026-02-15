# EPIC 12: Weekly Review / Retrospectiva Pessoal

> **Sessão Claude Code:** Criar o hábito semanal de reflexão.
> **Prioridade:** #4 (alto impacto, baixo esforço)
> **Estimativa:** ~45min · 2 tasks
> **Dependência:** Epic 11 (Status Time Tracking) para métricas ricas

---

## Contexto

Nenhuma ferramenta solo oferece retrospectiva estruturada. A Weekly Review consolida: tasks completadas, horas investidas, tasks paradas, padrões de produtividade. É a feature que cria o hábito de reflexão semanal.

---

## Task 12.1 — Model WeeklyReview + Geração automática

```yaml
Prompt: |
    Crie model e lógica para Weekly Reviews:

    Migration: weekly_reviews
    - id, week_start (date, unique — sempre segunda-feira),
      week_end (date), notes (text nullable),
      reflection (text nullable — campo para reflexão pessoal),
      generated_at (datetime)
    - timestamps

    Model: WeeklyReview
    - Fillable, casts
    - Scopes: forWeek($date), latest()
    - Helper: getOrGenerateForWeek($date) — cria review se não existe
    - Computed data (não persistido, calculado on-demand):
      - completedTasks(): tasks com completed_at na semana
      - totalHours(): soma de TimeEntries na semana
      - hoursByProject(): horas agrupadas por projeto
      - staleTasks(): tasks em backlog/todo há mais de 7 dias sem mudança
      - statusTimeAverages(): tempo médio por coluna das tasks completadas
      - tasksCreatedVsCompleted(): ratio criação/conclusão

    Artisan Command: soloboard:weekly-review (gera review da semana anterior)
    - Pode ser agendado no schedule (todo domingo/segunda configurável)

Acceptance Criteria:
    - getOrGenerateForWeek() cria review com dados corretos
    - completedTasks() retorna apenas tasks da semana
    - hoursByProject() agrupa corretamente
    - staleTasks() identifica tasks paradas
    - Teste Feature: gerar review, verificar dados

Arquivos:
    - database/migrations/*_create_weekly_reviews_table.php
    - app/Models/WeeklyReview.php
    - app/Console/Commands/WeeklyReviewCommand.php
    - routes/console.php (schedule)
    - tests/Feature/WeeklyReviewTest.php

Commit: "feat: WeeklyReview model with computed metrics and auto-generation"
```

---

## Task 12.2 — Página SFC: Weekly Review

```yaml
Prompt: |
    Crie o SFC resources/views/pages/⚡weekly-review.blade.php:

    Header:
    - Navegação entre semanas (< Semana anterior | Semana de DD/MM - DD/MM | Próxima >)
    - Badge se é a semana atual

    Seção "Resumo":
    - Cards: tasks completadas, horas totais, projetos trabalhados, tasks criadas
    - Ratio: completadas/criadas (com indicador verde/vermelho)

    Seção "Horas por Projeto":
    - <flux:chart.bar> horizontal com cores dos projetos
    - Total de horas por projeto na semana

    Seção "Tasks Completadas":
    - Lista com: título, projeto (badge), tempo total tracked, status timeline compacta
    - Agrupadas por projeto

    Seção "Atenção Necessária":
    - Tasks paradas há +7 dias (stale) com badge de alerta
    - Tasks overdue
    - Botão "Mover para Backlog" / "Arquivar" por task

    Seção "Reflexão":
    - <flux:textarea> para notas pessoais (debounce 500ms, salva automaticamente)
    - Placeholder: "O que funcionou bem? O que pode melhorar?"

    Seção "Histórico":
    - Link para reviews anteriores (lista compacta)

    Adicionar na sidebar:
    - Item "Review" (icon: clipboard-document-check) entre "Tempo" e última posição

    Rota: Route::livewire('/review', 'pages.weekly-review')->name('review')->middleware('auth');

Acceptance Criteria:
    - Navegação entre semanas funciona
    - Todas as métricas calculam corretamente
    - Chart de horas por projeto renderiza
    - Tasks paradas listadas com ações
    - Reflexão salva com debounce
    - Sidebar atualizada com novo item
    - Teste Feature: gerar review, verificar página

Arquivos:
    - resources/views/pages/⚡weekly-review.blade.php
    - resources/views/layouts/app.blade.php (sidebar item)
    - routes/web.php
    - tests/Feature/WeeklyReviewPageTest.php

Commit: "feat: Weekly Review page SFC with metrics, charts, and reflection"
```
