# EPIC 8: Time Reports

> **Sessão Claude Code:** Relatórios.
> **Estimativa:** ~30min · 1 task

---

## Task 8.1 — Página SFC: Time Report

```yaml
Prompt: |
    Crie o SFC resources/views/pages/⚡time-report.blade.php:
    - Filtros: período (today/week/month/custom) + projeto + date-picker range
    - <flux:table>: Data, Task, Projeto, Duração, Notas
    - Totais por dia e geral
    - Botão "Copiar como Markdown"
    - Suportar query param ?period=today (vindo do Dashboard)

    Rota: Route::livewire('/time', 'pages.time-report')->name('time')->middleware('auth');

Acceptance Criteria:
    - Filtros funcionam, inclusive via query param
    - Tabela lista entries corretas
    - Totais calculam certo
    - Copiar Markdown funciona
    - Teste Feature: criar entries, filtrar

Arquivos:
    - resources/views/pages/⚡time-report.blade.php
    - routes/web.php
    - tests/Feature/TimeReportTest.php

Commit: "feat: Time report SFC with Flux table and Markdown export"
```
