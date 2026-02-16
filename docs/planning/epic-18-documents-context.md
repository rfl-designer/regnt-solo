# Documento de Contexto: Epic 18 — Documents (Markdown Pages & MCP Context)

## 1. Resumo Executivo

Implementar o sistema de **Documents** no SoloBoard — páginas Markdown associadas a projetos (ou globais), com renderização rica, editor com preview, e integração no Project Detail e Task Modal. Os Documents servem como repositório centralizado de PRDs, specs, decisões e notas, acessíveis via UI e futuramente via MCP.

A feature se divide em 3 camadas:
1. **Document Model + Enum** — Modelo de dados com tipos, slugs scoped, e relacionamento com Project
2. **Markdown Rendering** — Componente reutilizável de visualização + helper de renderização + Tailwind Typography
3. **UI Pages** — Lista, visualização, editor, integração no Project Detail (aba Docs) e Task Modal (session fields como markdown)

---

## 2. Requisitos

### Funcionais

- **Document CRUD**: Criar, editar, visualizar e deletar documentos Markdown
- **Tipos de documento**: PRD, Spec, Decision, Note, Reference (enum com labels PT-BR, ícones, cores)
- **Associação a projetos**: Documents pertencem a um Project (nullable — globais se sem projeto)
- **Slug scoped**: Slug único por project_id (ou globalmente se project_id = null)
- **Pinned documents**: Flag `is_pinned` para fixar documentos no topo
- **Markdown rendering**: `Str::markdown()` + Tailwind Typography (`prose prose-invert`) + syntax highlighting
- **Markdown viewer component**: Componente reutilizável com botões de copiar (raw markdown e HTML)
- **Editor com preview**: Página de edição com `<flux:editor>` e toggle preview
- **Sidebar**: Novo item "Docs" entre Projetos e Tempo
- **Project Detail**: Nova aba "Docs" entre Tasks e Métricas
- **Task Modal**: Renderizar `session_prompt` e `session_result` como markdown (seções colapsáveis)
- **Cascade delete**: Deletar projeto remove seus documents

### Não-Funcionais

- Markdown renderizado server-side via `Str::markdown()` (CommonMark)
- HTML sanitizado (strip raw HTML, block unsafe links)
- Syntax highlighting via highlight.js CDN (lazy-loaded)
- Dark mode consistente com o restante da aplicação
- Performance: sem N+1 queries ao listar documents

---

## 3. Análise do Codebase

### 3.1 Estrutura de Diretórios Relevante

```
app/
├── Enums/
│   ├── TaskStatus.php          ← Padrão: enum com label(), color(), icon()
│   ├── TaskPriority.php        ← Padrão: enum com label(), color(), icon()
│   ├── ProjectStatus.php       ← Padrão: enum com label(), color(), icon()
│   └── ProjectPriority.php     ← Padrão: enum com label(), color(), icon()
├── Models/
│   ├── Project.php             ← Adicionar hasMany(Document)
│   ├── Task.php                ← Já tem session_prompt/session_result
│   ├── TimeEntry.php
│   ├── TaskCommit.php
│   ├── TaskStatusChange.php
│   ├── DailyPlan.php
│   ├── WeeklyReview.php
│   └── User.php
├── Observers/
│   └── TaskObserver.php
├── Services/
│   ├── AnalyticsService.php
│   └── AiAssistantService.php
├── Support/                    ← NÃO EXISTE — criar para Markdown.php
├── Mcp/
│   ├── Servers/SoloBoardServer.php
│   ├── Tools/ (14 tools)
│   ├── Resources/ (1 resource)
│   └── Prompts/ (2 prompts)
├── Http/Middleware/
│   └── McpAuth.php
└── Livewire/Actions/
    └── Logout.php

resources/views/
├── pages/
│   ├── ⚡dashboard.blade.php
│   ├── ⚡inbox.blade.php
│   ├── ⚡kanban.blade.php
│   ├── ⚡daily-planner.blade.php
│   ├── ⚡projects.blade.php
│   ├── ⚡project-detail.blade.php   ← Adicionar aba "Docs"
│   ├── ⚡time-report.blade.php
│   ├── ⚡weekly-review.blade.php
│   ├── ⚡analytics.blade.php
│   └── settings/ (profile, password, etc.)
├── components/
│   ├── ⚡task-modal.blade.php       ← Adicionar session fields markdown
│   ├── ⚡task-quick-add.blade.php   ← Sem alterações
│   ├── ⚡timer.blade.php
│   ├── ⚡timer-notes-modal.blade.php
│   ├── ⚡global-timer.blade.php
│   ├── ⚡command-palette.blade.php
│   ├── ⚡project-form.blade.php
│   └── ⚡inbox-badge.blade.php
├── layouts/
│   ├── app.blade.php               ← Layout wrapper
│   └── app/
│       ├── sidebar.blade.php       ← Adicionar item "Docs"
│       └── header.blade.php
└── css/
    └── app.css                     ← Adicionar estilos .markdown-viewer

database/
├── migrations/ (15 migrations)
├── factories/ (8 factories)
└── seeders/
    └── DatabaseSeeder.php          ← Adicionar seed de Documents

tests/
├── Feature/ (48 test files)
└── Unit/ (1 test file)
```

### 3.2 Modelos Existentes e Relacionamentos

```
User
├── (sem relações diretas com tasks/projects — app single-user)

Project
├── hasMany(Task)
├── [ADICIONAR] hasMany(Document)
├── Scopes: active(), paused(), archived(), ordered()
├── Casts: status → ProjectStatus, priority → ProjectPriority
├── Fillable: name, slug, color, emoji, status, priority, description

Task
├── belongsTo(Project)
├── hasMany(TaskCommit)
├── hasMany(TimeEntry)
├── hasMany(TaskStatusChange)
├── belongsToMany(DailyPlan)
├── Fillable: project_id, title, description, status, priority, due_date,
│             estimated_minutes, completed_at, sort_order, pr_url,
│             session_prompt, session_result
├── Casts: status → TaskStatus, priority → TaskPriority, due_date → date, completed_at → datetime
├── Accessors: timeInStatus, currentStatusDuration
├── Methods: isOverdue(), isRunning(), commitCount(), isSessionTask(), sessionSummary(), markAsDone()
├── Scopes: inbox(), active(), byStatus(), overdue(), unassigned(), doneThisWeek()
└── Observer: TaskObserver (tracks status changes)

TimeEntry
├── belongsTo(Task)
├── Accessor: durationMinutes
├── Scopes: running(), forDate(), forWeek(), focusSessions()

TaskCommit
├── belongsTo(Task)
├── Scopes: forTask(), recent()

TaskStatusChange
├── belongsTo(Task)
├── Scopes: forTask(), forStatus()

DailyPlan
├── belongsToMany(Task)
├── Methods: getOrCreateForDate(), completionRate(), incompleteTasks()

WeeklyReview
├── Scopes: forWeek()
├── Methods: getOrCreateForWeek(), completedTasks(), totalHours(), hoursByProject(), staleTasks()
```

### 3.3 Padrões e Convenções Identificados

#### Enums
- Todos em `App\Enums\` com backing type `string`
- Keys em TitleCase (ex: `case Inbox = 'inbox'`)
- Métodos padrão: `label()` (PT-BR), `color()` (Flux color name), `icon()` (Heroicon name)
- TaskStatus também tem `hexColor()` para inline styles

#### Models
- `$fillable` array (não `$guarded`)
- Casts via método `casts()` (não property `$casts`)
- PHPDoc blocks em todos os métodos
- Scopes como `scopeXxx(Builder $query): void`
- Accessors via `Attribute::get()`
- HasFactory trait com `@use` annotation

#### Livewire SFCs (Single File Components)
- Arquivos `⚡nome.blade.php` em `resources/views/pages/` (páginas) e `resources/views/components/` (componentes)
- PHP class `new class extends Component` no topo do arquivo
- `#[Computed]` para propriedades computadas
- `#[On('event-name')]` para listeners
- `Flux::toast()` para notificações
- `wire:navigate` para navegação SPA

#### Routes
- `Route::livewire('path', 'pages::component-name')` com `->middleware(['auth'])->name('route.name')`
- Todas as rotas protegidas por `auth` middleware

#### Sidebar
- Usa `<flux:sidebar.item>` com `icon`, `:href`, `:current`, `wire:navigate`
- Ordem atual: Dashboard, Inbox, Kanban, Daily, Projetos, Tempo, Review, Analytics
- "Docs" deve entrar entre "Projetos" e "Tempo"

#### CSS
- Tailwind CSS v4 com `@import 'tailwindcss'`
- Flux CSS importado
- Custom dark mode variant
- Tema customizado com cores zinc
- Sem `@tailwindcss/typography` instalado (PRECISA INSTALAR)

#### Tests
- Pest v4 com `php artisan test --compact`
- Feature tests predominam (~48 arquivos)
- Factories usadas extensivamente
- Padrão: `actingAs(User::factory()->create())` para autenticação

#### Factories
- Padrão: `definition()` com defaults + states nomeados (`done()`, `doing()`, `session()`, etc.)
- TaskFactory já tem state `session()` para session_prompt/session_result

#### Seeder
- `DatabaseSeeder` com métodos privados organizados por entidade
- Dados realistas em PT-BR
- `Task::withoutEvents()` para evitar observer durante seed

---

## 4. Dependências

### 4.1 Epic 15 (Task-as-Session) — STATUS: ✅ IMPLEMENTADO

Os campos `session_prompt` e `session_result` **já existem** na tabela `tasks`:
- Migration: `2026_02_15_145317_add_session_fields_to_tasks_table.php`
- Task model: ambos em `$fillable`, usados em `isSessionTask()`, `sessionSummary()`
- Task modal: já renderiza session fields (como textarea/text, NÃO como markdown)
- Task quick-add: já suporta criação de session tasks (checkbox + prefixo `>`)
- TaskFactory: já tem state `session()`
- DatabaseSeeder: já cria 3 session tasks com prompts/results

**Conclusão**: Task 18.2 pode focar apenas em adicionar renderização markdown nos session fields existentes.

### 4.2 Externas (a instalar)

| Pacote | Propósito | Ação |
|--------|-----------|------|
| `@tailwindcss/typography` | Classes `prose` para renderização markdown | `npm install @tailwindcss/typography` |
| highlight.js (CDN) | Syntax highlighting em blocos de código | Incluir via `<link>` e `<script>` CDN |

**Nota sobre Tailwind CSS v4 + Typography**: No Tailwind v4, o plugin typography é importado via `@import '@tailwindcss/typography'` no CSS, não via config file.

### 4.3 Internas (existentes, sem alteração)

- `Illuminate\Support\Str::markdown()` — já disponível no Laravel 12
- `<flux:editor>` — já usado no Task Modal para description
- `<flux:card>`, `<flux:badge>`, `<flux:tab.group>` — componentes Flux já usados
- `<flux:modal>` — já usado extensivamente

---

## 5. Riscos e Mitigações

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| `@tailwindcss/typography` incompatível com Tailwind v4 | Baixa | Alto | Verificar docs do Tailwind v4 para import correto; testar antes de prosseguir |
| `prose prose-invert` não estiliza corretamente em dark mode | Média | Médio | Testar com conteúdo real; ajustar com classes CSS customizadas se necessário |
| `<flux:editor>` não suporta markdown raw (é rich text) | Média | Alto | Verificar se `<flux:editor>` retorna markdown ou HTML; se HTML, usar `<flux:textarea>` como fallback |
| Slug collision em documents globais (project_id null) | Baixa | Médio | Implementar unique constraint com composite index; validar no model boot |
| highlight.js CDN indisponível offline | Baixa | Baixo | Aceitável para app local; alternativa: bundle via npm |
| Performance ao renderizar markdown grande | Baixa | Baixo | `Str::markdown()` é rápido; cache se necessário no futuro |
| Conflito de rotas `/docs` com possível rota existente | Baixa | Médio | Verificar — NÃO existe rota `/docs` atualmente |

---

## 6. Tecnologias e Ferramentas

| Tecnologia | Versão | Uso |
|------------|--------|-----|
| Laravel | 12.51.0 | Framework base, `Str::markdown()` |
| Livewire | 4.1.4 | SFCs para páginas e componentes |
| Flux UI Pro | 2.12.0 | Componentes UI (editor, cards, tabs, modals, badges) |
| Tailwind CSS | 4.1.18 | Estilização + Typography plugin |
| @tailwindcss/typography | A instalar | Classes `prose` para markdown renderizado |
| highlight.js | 11.9.0 (CDN) | Syntax highlighting em code blocks |
| Pest | 4.3.2 | Testes |
| SQLite | — | Database (cascade delete via FK) |

---

## 7. Escopo

### Incluído

- Model `Document` com migration, factory, seeder
- Enum `DocumentType` com 5 tipos
- Helper `app/Support/Markdown.php` (render + excerpt)
- Componente `⚡markdown-viewer.blade.php` (reutilizável)
- Página `⚡documents.blade.php` (lista com filtros)
- Página `⚡document-view.blade.php` (visualização full-page)
- Página `⚡document-edit.blade.php` (editor com preview toggle)
- Sidebar: item "Docs" com ícone `document-text`
- Project Detail: aba "Docs" com lista de documents do projeto
- Task Modal: renderização markdown dos session fields (colapsáveis)
- Rotas: `/docs`, `/docs/new`, `/docs/{slug}`, `/docs/{slug}/edit`
- CSS: estilos `.markdown-viewer` para dark mode
- Testes Feature para todas as funcionalidades

### Excluído

- MCP endpoints para Documents (será implementado separadamente no MCP Server)
- Versionamento de documents (git faz isso)
- Sub-páginas / nesting de documents
- Drag-and-drop para reordenação
- Busca full-text em documents
- Import/export de markdown files
- Collaborative editing

---

## 8. Mapeamento Detalhado de Arquivos Afetados

### Novos Arquivos

| Arquivo | Tipo | Task |
|---------|------|------|
| `database/migrations/*_create_documents_table.php` | Migration | 18.1 |
| `app/Models/Document.php` | Model | 18.1 |
| `app/Enums/DocumentType.php` | Enum | 18.1 |
| `database/factories/DocumentFactory.php` | Factory | 18.1 |
| `app/Support/Markdown.php` | Helper | 18.3 |
| `resources/views/components/⚡markdown-viewer.blade.php` | SFC Component | 18.3 |
| `resources/views/pages/⚡documents.blade.php` | SFC Page | 18.4 |
| `resources/views/pages/⚡document-view.blade.php` | SFC Page | 18.4 |
| `resources/views/pages/⚡document-edit.blade.php` | SFC Page | 18.5 |
| `tests/Feature/DocumentTest.php` | Test | 18.1 |
| `tests/Feature/MarkdownViewerTest.php` | Test | 18.3 |
| `tests/Feature/DocumentListTest.php` | Test | 18.4 |
| `tests/Feature/DocumentEditTest.php` | Test | 18.5 |
| `tests/Feature/DocumentIntegrationTest.php` | Test | 18.6 |

### Arquivos Modificados

| Arquivo | Alteração | Task |
|---------|-----------|------|
| `app/Models/Project.php` | Adicionar `hasMany(Document)` | 18.1 |
| `resources/css/app.css` | Adicionar `@import` typography + estilos `.markdown-viewer` | 18.3 |
| `package.json` | Adicionar `@tailwindcss/typography` | 18.3 |
| `resources/views/layouts/app/sidebar.blade.php` | Adicionar item "Docs" | 18.4 |
| `routes/web.php` | Adicionar rotas `/docs/*` | 18.4, 18.5 |
| `resources/views/pages/⚡project-detail.blade.php` | Adicionar aba "Docs" | 18.6 |
| `resources/views/components/⚡task-modal.blade.php` | Markdown rendering nos session fields | 18.6 |
| `database/seeders/DatabaseSeeder.php` | Adicionar seed de Documents | 18.1 |

---

## 9. Detalhes Técnicos Importantes

### 9.1 Sidebar — Posição Exata do Item "Docs"

```blade
{{-- Atual (sidebar.blade.php linhas 14-39) --}}
<flux:sidebar.item icon="folder" ...>Projetos</flux:sidebar.item>
{{-- INSERIR AQUI --}}
<flux:sidebar.item icon="document-text" :href="route('documents')" :current="request()->routeIs('documents') || request()->routeIs('document.*')" wire:navigate>
    Docs
</flux:sidebar.item>
{{-- Continua --}}
<flux:sidebar.item icon="clock" ...>Tempo</flux:sidebar.item>
```

### 9.2 Project Detail — Aba "Docs" (entre Tasks e Métricas)

```blade
{{-- Atual (project-detail.blade.php linhas 197-201) --}}
<flux:tabs wire:model="tab">
    <flux:tab name="tasks" icon="clipboard-document-list">Tasks</flux:tab>
    {{-- INSERIR AQUI --}}
    <flux:tab name="docs" icon="document-text">Docs</flux:tab>
    {{-- Continua --}}
    <flux:tab name="metrics" icon="chart-bar-square">Métricas</flux:tab>
</flux:tabs>
```

### 9.3 Task Modal — Session Fields Atuais (linhas 354-455)

Os session fields já existem no Task Modal mas são renderizados como:
- `session_prompt`: `<div>` com texto plain (quando done) ou `<flux:textarea>` (quando editável)
- `session_result`: `<flux:textarea>`

**Alteração necessária**: Substituir por renderização markdown usando `<livewire:markdown-viewer>` quando em modo visualização, mantendo `<flux:editor>` para edição.

### 9.4 Route Pattern

```php
// Padrão existente:
Route::livewire('path', 'pages::component-name')
    ->middleware(['auth'])
    ->name('route.name');

// Novas rotas:
Route::livewire('docs', 'pages::documents')
    ->middleware(['auth'])
    ->name('documents');

Route::livewire('docs/new', 'pages::document-edit')
    ->middleware(['auth'])
    ->name('document.create');

Route::livewire('docs/{slug}', 'pages::document-view')
    ->middleware(['auth'])
    ->name('document.view');

Route::livewire('docs/{slug}/edit', 'pages::document-edit')
    ->middleware(['auth'])
    ->name('document.edit');
```

**IMPORTANTE**: A rota `docs/new` DEVE vir antes de `docs/{slug}` para evitar que "new" seja interpretado como slug.

### 9.5 Tailwind Typography no v4

```css
/* resources/css/app.css — adicionar após @import 'tailwindcss' */
@import '@tailwindcss/typography';
```

### 9.6 MCP Server — Preparação Futura

O `SoloBoardServer.php` já tem 14 tools, 1 resource, 2 prompts. Os endpoints de Documents (list_documents, get_document, create_document, update_document, get_task_context, get_project_context) serão adicionados como novas Tools em uma task separada, consumindo o modelo de dados criado neste Epic.

---

## 10. Próximos Passos

Recomendação para task-breakdown:

1. **Task 18.1** — Document model + migration + enum + factory + seeder + relationship no Project
2. **Task 18.3** — Markdown helper + viewer component + CSS + typography install (antes de 18.2)
3. **Task 18.2** — Markdown rendering nos session fields do Task Modal (depende de 18.3)
4. **Task 18.4** — Páginas de lista e visualização + sidebar + rotas
5. **Task 18.5** — Página de editor com preview toggle
6. **Task 18.6** — Integração no Project Detail (aba Docs) + Task Modal (docs do projeto)

**Estimativa total**: ~2-3 horas de implementação
