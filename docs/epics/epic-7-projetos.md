# EPIC 7: Projetos CRUD

> **Sessão Claude Code:** Gestão de projetos.
> **Estimativa:** ~45min · 3 tasks

---

## Task 7.1 — Página SFC: Lista de Projetos

```yaml
Prompt: |
    Crie o SFC resources/views/pages/⚡projects.blade.php:
    - Tabs filtro: Active | Paused | Archived
    - Grid 3 colunas <flux:card>: emoji + nome + borda cor + badge status + tasks ativas count
    - Click → navega para detalhe
    - Botão "Novo Projeto" dispatch open-project-form

    Rota: Route::livewire('/projects', 'pages.projects')->name('projects')->middleware('auth');

Acceptance Criteria:
    - Grid renderiza, filtro funciona, click navega
    - Teste Feature: listar, filtrar

Arquivos:
    - resources/views/pages/⚡projects.blade.php
    - routes/web.php
    - tests/Feature/ProjectListTest.php

Commit: "feat: Project list SFC with status filter"
```

---

## Task 7.2 — Componente SFC: Project Form Modal

```yaml
Prompt: |
    Crie o SFC resources/views/components/⚡project-form.blade.php:
    - <flux:modal> criar/editar projeto
    - Campos: name, emoji, color (input type color), priority, status, description
    - Slug auto-gerado
    - Listeners: 'open-project-form', 'edit-project'
    - Labels em PT-BR

Acceptance Criteria:
    - Criar e editar funciona, validação Livewire
    - Teste Feature: criar, editar

Arquivos:
    - resources/views/components/⚡project-form.blade.php
    - tests/Feature/ProjectFormTest.php

Commit: "feat: Project form modal SFC"
```

---

## Task 7.3 — Página SFC: Detalhe do Projeto

```yaml
Prompt: |
    Crie o SFC resources/views/pages/⚡project-detail.blade.php:
    - Header: emoji + nome + cor + badge status + botões editar/arquivar
    - Abas: Tasks | Métricas
    - Tab Tasks: MINI-KANBAN HORIZONTAL (colunas compactas por status dentro do projeto)
    - Tab Métricas: horas totais, tasks por status
    - Botão "Nova Task" (pre-seleciona projeto)

    Rota: Route::livewire('/projects/{slug}', 'pages.project-detail')->name('project.detail')->middleware('auth');

Acceptance Criteria:
    - Carrega pelo slug
    - Mini-kanban mostra tasks por status
    - Arquivar muda status
    - Teste Feature: acessar detalhe, verificar mini-kanban

Arquivos:
    - resources/views/pages/⚡project-detail.blade.php
    - routes/web.php
    - tests/Feature/ProjectDetailTest.php

Commit: "feat: Project detail SFC with mini-kanban and metrics tabs"
```
