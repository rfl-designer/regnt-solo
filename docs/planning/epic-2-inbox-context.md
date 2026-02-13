# Documento de Contexto: Epic 2 — Inbox & Captura Rápida

## 1. Resumo Executivo

Implementar o sistema de Inbox e Captura Rápida do SoloBoard, composto por:
1. **TaskQuickAdd** — Modal overlay global (hotkey `N`) com input inteligente que parseia sintaxe inline (`#projeto`, `!prioridade`, `@data`) com autocomplete, criando tasks sempre com `status=inbox`.
2. **Página Inbox** — Lista de tasks com status inbox, com ações de triagem (atribuir projeto, mover para backlog, deletar) e badge reativo na sidebar.

---

## 2. Requisitos

### Funcionais

#### Task 2.1 — TaskQuickAdd Modal Overlay
- [ ] Modal overlay ativado por hotkey `N` de qualquer tela (ignorar quando foco em input/textarea/select/contenteditable)
- [ ] Usa `<flux:modal name="quick-add">`
- [ ] Input com `wire:model="title"` e parsing de sintaxe inline:
  - `#slug` → associar projeto via dropdown autocomplete ao digitar `#`
  - `!prioridade` → definir prioridade (autocomplete: urgent, high, medium, low)
  - `@data` → definir due_date (autocomplete: @hoje, @amanha, @segunda, @proxima-semana)
- [ ] Autocomplete funcional para os 3 prefixos
- [ ] Se `#slug` não existe: toast aviso "Projeto #xyz não encontrado", cria task sem projeto
- [ ] Sempre cria com `status=inbox` (independente da tela atual)
- [ ] `Enter` para criar, `Esc` para fechar
- [ ] Toast de confirmação após criação
- [ ] Input limpa após criar
- [ ] Incluído no layout `app.blade.php` para disponibilidade global
- [ ] Dispara evento para atualizar badge do inbox na sidebar

#### Task 2.2 — Página Inbox
- [ ] Lista tasks com `status=inbox`, com eager loading de `project`, ordenadas por `latest`
- [ ] Cada card: título, tempo desde criação (diffForHumans), projeto (se tiver)
- [ ] Ações por task:
  - `<flux:select>` para atribuir projeto (inline, compact)
  - Botão "→ Backlog" para mover (`status=backlog`)
  - Botão delete com `<flux:modal>` de confirmação
- [ ] Empty state: ícone inbox + "Nenhuma task no inbox. Pressione N para criar."
- [ ] Badge do inbox na sidebar atualiza via evento reativo
- [ ] Rota: `Route::livewire('/inbox', 'pages::inbox')->name('inbox')->middleware('auth')`

### Não-Funcionais
- Interface em PT-BR (labels, botões, mensagens, toasts, empty states)
- Código em inglês (variáveis, classes, métodos)
- Dark mode only (sem light mode)
- SFC (Single-File Components) — sem arquivos PHP separados em `app/Livewire/`
- Testes Feature com Pest para toda lógica de negócio
- Keyboard-first: suporte a atalhos

---

## 3. Análise do Codebase

### Estrutura Relevante

```
app/
├── Models/
│   └── User.php                    # Único model existente
├── Livewire/
│   └── Actions/
│       └── Logout.php              # Único Livewire class-based
├── Enums/                          # NÃO EXISTE — precisa ser criado
├── Providers/
│   ├── AppServiceProvider.php
│   └── FortifyServiceProvider.php

resources/views/
├── layouts/
│   ├── app.blade.php               # Layout principal (wrapper para sidebar)
│   └── app/
│       ├── sidebar.blade.php       # Sidebar com nav items (PRECISA de badge inbox)
│       └── header.blade.php        # Header alternativo (não usado atualmente)
├── pages/
│   └── ⚡dashboard.blade.php       # Única page SFC existente
├── components/
│   ├── desktop-user-menu.blade.php
│   ├── app-logo.blade.php
│   └── ...                         # Componentes Blade estáticos
├── flux/                           # Overrides de componentes Flux
│   ├── navlist/group.blade.php
│   └── icon/...

routes/
├── web.php                         # Rota principal: dashboard + redirect /
├── settings.php                    # Rotas de settings (profile, password, etc.)

tests/
├── Pest.php                        # Config: RefreshDatabase em Feature tests
├── Feature/
│   ├── DashboardTest.php           # Padrão de teste existente
│   └── Auth/...
```

### Estado do Banco de Dados

As tabelas do Epic 1 **existem no banco SQLite** (migrations rodaram), mas os **arquivos de código NÃO existem**:

| Artefato | Tabela no DB | Arquivo no Filesystem |
|----------|-------------|----------------------|
| `projects` | ✅ Existe (5 registros seed) | ❌ Model, Enum, Factory, Migration ausentes |
| `tasks` | ✅ Existe (1 registro) | ❌ Model, Enum, Factory, Migration ausentes |
| `time_entries` | ✅ Existe (0 registros) | ❌ Model, Factory, Migration ausentes |
| `daily_plans` | ✅ Existe (0 registros) | ❌ Model, Factory, Migration ausentes |
| `daily_plan_task` | ✅ Existe (pivot) | ❌ Migration ausente |

**Migrations registradas no banco (batch 2-5):**
- `2026_02_13_105915_create_projects_table`
- `2026_02_13_110225_create_tasks_table`
- `2026_02_13_110623_create_time_entries_table`
- `2026_02_13_110930_create_daily_plans_table`
- `2026_02_13_110933_create_daily_plan_task_table`

### Schema Relevante

**tasks:**
| Coluna | Tipo | Nullable | Default |
|--------|------|----------|---------|
| id | integer | N | auto |
| project_id | integer | Y | null |
| title | varchar | N | — |
| description | text | Y | null |
| status | varchar | N | 'inbox' |
| priority | varchar | N | 'medium' |
| due_date | date | Y | null |
| estimated_minutes | integer | Y | null |
| completed_at | datetime | Y | null |
| sort_order | integer | N | 0 |
| FK: project_id → projects.id (ON DELETE SET NULL) |

**projects:**
| Coluna | Tipo | Nullable | Default |
|--------|------|----------|---------|
| id | integer | N | auto |
| name | varchar | N | — |
| slug | varchar | N | — (unique) |
| color | varchar | N | '#6366f1' |
| emoji | varchar | N | '📋' |
| status | varchar | N | 'active' |
| priority | varchar | N | 'medium' |
| description | text | Y | null |

### Padrões Identificados

1. **SFC (Single-File Components)**: Todas as pages usam formato `⚡nome.blade.php` com `new class extends Component {}` no topo
2. **Rotas**: `Route::livewire()` em `routes/web.php` com `->middleware(['auth'])`
3. **Layout**: `layouts::app` → wrapper que inclui `layouts::app.sidebar`
4. **Flux UI**: Componentes `<flux:*>` usados extensivamente (sidebar, menu, heading, text, icon, etc.)
5. **Testes Pest**: `RefreshDatabase` em Feature tests, `User::factory()->create()`, `$this->actingAs()`
6. **Dark mode**: Classe `dark` fixa no `<html>`, cores `zinc-700/800/900`
7. **Sidebar nav**: Items com `<flux:sidebar.item icon="..." :href="..." :current="..." wire:navigate>`
8. **Todos os links da sidebar apontam para `route('dashboard')`** — são placeholders

### Dados Seed Existentes (5 projetos)

| ID | Name | Slug | Status | Priority |
|----|------|------|--------|----------|
| 1 | Website Redesign | website-redesign | active | high |
| 2 | API Integration | api-integration | active | medium |
| 3 | Mobile App | mobile-app | paused | high |
| 4 | Documentation | documentation | active | low |
| 5 | Legacy Migration | legacy-migration | archived | medium |

---

## 4. Dependências

### Externas (já instaladas)
- `livewire/livewire` v4.1.4 — SFC, wire:model, wire:click, eventos
- `livewire/flux-pro` v2.12.0 — Modal, Input, Select, Button, Toast, Sidebar
- `tailwindcss` v4.1.18 — Estilização
- `pestphp/pest` v4.3.2 — Testes

### Internas — PRECISAM SER CRIADAS (Pré-requisitos do Epic 1)

| Artefato | Caminho | Necessário para |
|----------|---------|-----------------|
| **Model Task** | `app/Models/Task.php` | Ambas as tasks |
| **Model Project** | `app/Models/Project.php` | Autocomplete #slug, select projeto |
| **Enum TaskStatus** | `app/Enums/TaskStatus.php` | Status inbox/backlog |
| **Enum TaskPriority** | `app/Enums/TaskPriority.php` | Parsing !prioridade |
| **Enum ProjectStatus** | `app/Enums/ProjectStatus.php` | Filtrar projetos ativos |
| **Enum ProjectPriority** | `app/Enums/ProjectPriority.php` | Model Project |
| **Factory TaskFactory** | `database/factories/TaskFactory.php` | Testes |
| **Factory ProjectFactory** | `database/factories/ProjectFactory.php` | Testes |
| **Migration create_projects** | `database/migrations/...` | Reprodutibilidade |
| **Migration create_tasks** | `database/migrations/...` | Reprodutibilidade |

### Módulos Afetados
- `resources/views/layouts/app.blade.php` — Incluir TaskQuickAdd
- `resources/views/layouts/app/sidebar.blade.php` — Badge inbox + href correto
- `routes/web.php` — Nova rota `/inbox`

---

## 5. Riscos e Mitigações

| # | Risco | Probabilidade | Impacto | Mitigação |
|---|-------|---------------|---------|-----------|
| 1 | **Epic 1 não implementado** — Models, Enums, Factories e Migrations não existem no filesystem, apenas tabelas no banco | **Alta** | **Crítico** | Implementar Epic 1 (Models + Enums + Factories + Migrations) ANTES do Epic 2. As tabelas já existem no banco, então as migrations precisam ser criadas mas NÃO rodadas (ou usar `--pretend`). Alternativa: criar apenas os Models/Enums/Factories sem migrations, já que as tabelas existem. |
| 2 | **Parsing de sintaxe inline complexo** — Parsear `#slug`, `!prioridade`, `@data` do mesmo input com autocomplete | Média | Alto | Implementar parser robusto com regex. Usar Alpine.js para autocomplete client-side. Testar edge cases (múltiplos prefixos, prefixos parciais). |
| 3 | **Autocomplete reativo** — Dropdown de sugestões precisa aparecer em tempo real ao digitar | Média | Médio | Usar Alpine.js `x-on:input` para detectar prefixos e mostrar dropdown. Buscar projetos via Livewire action. |
| 4 | **Badge reativo na sidebar** — Precisa atualizar sem reload ao criar/mover/deletar task | Média | Médio | Usar Livewire events (`$dispatch`) para comunicação entre componentes. A sidebar é Blade estático, pode precisar de um componente Livewire para o badge. |
| 5 | **Hotkey `N` conflito** — Pode conflitar com inputs de texto | Baixa | Baixo | Ignorar hotkey quando foco em `input`/`textarea`/`select`/`[contenteditable]` (já documentado no CLAUDE.md). |
| 6 | **RefreshDatabase nos testes** — Vai limpar as tabelas existentes, precisa de Factories | Alta | Alto | Criar Factories para Task e Project antes dos testes. |

---

## 6. Tecnologias e Ferramentas

| Tecnologia | Versão | Uso |
|------------|--------|-----|
| PHP | 8.4.17 | Backend, Enums nativos |
| Laravel | 12.51.0 | Framework, Eloquent, Routes |
| Livewire | 4.1.4 | SFC, wire:model, eventos, actions |
| Flux UI Pro | 2.12.0 | Modal, Input, Select, Button, Toast, Sidebar badge |
| Alpine.js | (bundled com Livewire) | Hotkey listener, autocomplete dropdown |
| Tailwind CSS | 4.1.18 | Estilização dark mode |
| Pest | 4.3.2 | Testes Feature |
| SQLite | — | Banco de dados |

---

## 7. Escopo

### Incluído
- Criação dos Models Task e Project (pré-requisito do Epic 1)
- Criação dos Enums TaskStatus, TaskPriority, ProjectStatus, ProjectPriority
- Criação das Factories TaskFactory e ProjectFactory
- Criação das Migrations (para reprodutibilidade, mesmo que tabelas já existam)
- Componente SFC `TaskQuickAdd` com modal, parsing e autocomplete
- Página SFC `Inbox` com listagem, ações e empty state
- Badge reativo na sidebar
- Atualização do layout e rotas
- Testes Feature para ambas as tasks

### Excluído
- Models TimeEntry e DailyPlan (serão criados em Epics futuros)
- Kanban board (Epic 3)
- Timer/Time tracking (Epic 5)
- Command Palette `Cmd+K` (Epic 9)
- Outras hotkeys além de `N` (serão implementadas nos respectivos Epics)

---

## 8. Plano de Implementação

### Fase 0 — Pré-requisitos (Epic 1 parcial)
Criar a camada de dados necessária para o Epic 2:

1. **Enums** (4 arquivos):
   - `app/Enums/TaskStatus.php` — Inbox, Backlog, Todo, Doing, Done (com label PT-BR, color, icon)
   - `app/Enums/TaskPriority.php` — Urgent, High, Medium, Low
   - `app/Enums/ProjectStatus.php` — Active, Paused, Archived
   - `app/Enums/ProjectPriority.php` — High, Medium, Low

2. **Models** (2 arquivos):
   - `app/Models/Project.php` — fillable, casts (enums), scopes (active), relationship hasMany(Task)
   - `app/Models/Task.php` — fillable, casts (enums, completed_at), scopes (inbox, active, byStatus), relationship belongsTo(Project), helpers (isOverdue, markAsDone)

3. **Factories** (2 arquivos):
   - `database/factories/ProjectFactory.php`
   - `database/factories/TaskFactory.php`

4. **Migrations** (2 arquivos):
   - `create_projects_table` e `create_tasks_table` — para reprodutibilidade

5. **Testes** do Epic 1 (parcial):
   - `tests/Feature/ProjectTest.php`
   - `tests/Feature/TaskTest.php`

### Fase 1 — Task 2.1: TaskQuickAdd Modal
1. Criar SFC `resources/views/components/⚡task-quick-add.blade.php`
2. Implementar parsing de sintaxe inline (regex para #, !, @)
3. Implementar autocomplete com Alpine.js
4. Incluir no layout `resources/views/layouts/app.blade.php`
5. Criar `tests/Feature/TaskQuickAddTest.php`

### Fase 2 — Task 2.2: Página Inbox
1. Criar SFC `resources/views/pages/⚡inbox.blade.php`
2. Adicionar rota em `routes/web.php`
3. Implementar listagem, ações e empty state
4. Implementar badge reativo na sidebar (pode precisar de componente Livewire separado ou Alpine.js com eventos)
5. Atualizar sidebar: corrigir href do item Inbox para `route('inbox')`
6. Criar `tests/Feature/InboxTest.php`

---

## 9. Decisões Técnicas Pendentes

1. **Migrations**: As tabelas já existem no banco. Opções:
   - (A) Criar migrations normais e usar `php artisan migrate` (vai falhar se tabelas existem) — precisaria dropar e recriar
   - (B) Criar migrations normais para reprodutibilidade, mas não rodar (banco já está ok)
   - (C) Não criar migrations (menos ideal para reprodutibilidade)
   - **Recomendação**: Opção (B) — criar os arquivos de migration para que `RefreshDatabase` nos testes funcione corretamente. O `RefreshDatabase` do Pest vai dropar e recriar tudo automaticamente nos testes.

2. **Badge reativo na sidebar**: A sidebar é Blade estático. Opções:
   - (A) Criar um componente Livewire SFC para o badge e incluir na sidebar
   - (B) Usar Alpine.js com `$wire` para escutar eventos
   - (C) Usar `@livewire('inbox-badge')` inline na sidebar
   - **Recomendação**: Opção (A) ou (C) — componente Livewire SFC pequeno que escuta eventos e atualiza a contagem.

3. **Autocomplete no QuickAdd**: 
   - (A) Tudo server-side via Livewire (mais lento, mais simples)
   - (B) Alpine.js client-side com dados pré-carregados (mais rápido, mais complexo)
   - (C) Híbrido: carregar projetos via Livewire, autocomplete via Alpine
   - **Recomendação**: Opção (C) — carregar lista de projetos ativos no mount(), autocomplete via Alpine.js para responsividade.

---

## 10. Próximos Passos

1. **Aprovar este documento de contexto**
2. **Implementar Epic 1 parcial** (Models, Enums, Factories, Migrations para Project e Task)
3. **Implementar Task 2.1** — TaskQuickAdd Modal
4. **Implementar Task 2.2** — Página Inbox
5. **Rodar testes** e validar tudo funciona
6. **Rodar Pint** para formatação
