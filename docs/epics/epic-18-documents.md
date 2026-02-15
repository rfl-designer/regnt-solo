# EPIC 18: Documents â€” Markdown Pages & MCP Context

> **Objetivo:** Adicionar pÃ¡ginas Markdown ao SoloBoard (estilo artefatos do Claude / Notion pages),
> servir contexto rico via MCP, e enriquecer tasks com campos de sessÃ£o agentic.
> **Estimativa:** ~2-3 horas Â· 6 tasks
> **DependÃªncia:** Epics 0-1 (scaffold + models). Epic 15 (Task-as-Session) para session fields.
> **Paralelo:** Pode rodar em paralelo com Epics 2-9. Tasks 18.1-18.5 sÃ£o independentes do Epic 15.
> **PrÃ©-requisito MCP:** Epic 18 define o modelo de dados e UI. O MCP Server (Epic 10) consome esses dados.

---

## 1. VisÃ£o Geral

### Problema

O dev solo que usa Claude Code / Cursor / Windsurf precisa de **contexto estruturado** para suas sessÃµes de desenvolvimento:

- **PRD / Specs** â€” documentos de referÃªncia do projeto que o AI precisa ler antes de codar
- **System Prompts** â€” o prompt especÃ­fico de cada task (o que o AI deve implementar)
- **Notas de decisÃ£o** â€” decisÃµes de arquitetura, trade-offs, convenÃ§Ãµes

Hoje esse conteÃºdo fica espalhado em arquivos `.md` avulsos, Notion, Google Docs, ou na cabeÃ§a do dev. O SoloBoard pode centralizar tudo em **Documents** â€” pÃ¡ginas Markdown renderizadas com boa formataÃ§Ã£o, associadas a projetos, e acessÃ­veis via MCP.

### SoluÃ§Ã£o

TrÃªs camadas complementares:

| Camada                    | O que resolve                                          | ImplementaÃ§Ã£o                                    |
| ------------------------- | ------------------------------------------------------ | -------------------------------------------------- |
| **Documents (Pages)**     | PÃ¡ginas Markdown por projeto (PRDs, specs, decisÃµes) | Model `Document` + editor + visualizador           |
| **Task Session Fields**   | Campos `session_prompt` e `session_result` na Task     | Migration + campos no Task Modal                   |
| **MCP Context Endpoints** | Claude Code lÃª specs e prompts sem abrir browser      | Endpoints que servem documentos e contexto da task |

### PrincÃ­pios

1. **Markdown nativo** â€” `Str::markdown()` (CommonMark) + Tailwind Typography (`prose`) para renderizaÃ§Ã£o
2. **Flux Editor** â€” `<flux:editor>` para ediÃ§Ã£o (mesmo componente da descriÃ§Ã£o de tasks)
3. **Hierarquia simples** â€” Documents pertencem a um Project (ou sÃ£o globais). Sem nesting, sem sub-pÃ¡ginas
4. **RenderizaÃ§Ã£o estilo artefato** â€” modal ou pÃ¡gina full-width com markdown renderizado, dark mode, copy-to-clipboard
5. **MCP-ready** â€” todo conteÃºdo acessÃ­vel via endpoints HTTP para ferramentas AI

---

## 2. Modelo de Dados

### Document (Nova tabela)

```
Document
â”œâ”€â”€ id
â”œâ”€â”€ project_id (nullable FK â€” null = documento global, nÃ£o pertence a projeto)
â”œâ”€â”€ title (string)
â”œâ”€â”€ slug (string, unique per project â€” scoped unique)
â”œâ”€â”€ content (longText â€” markdown raw)
â”œâ”€â”€ type: enum(prd, spec, decision, note, reference)
â”œâ”€â”€ is_pinned (boolean, default false â€” documentos fixados aparecem primeiro)
â”œâ”€â”€ sort_order (integer, default 0)
â””â”€â”€ timestamps
```

**Regras:**

- `slug` Ã© unique **dentro do projeto** (ou globalmente se `project_id = null`)
- Slug auto-gerado do tÃ­tulo (como Project)
- Cascade delete: deletar projeto remove seus documents
- Sem versionamento no MVP â€” conteÃºdo Ã© o estado atual (git faz o versionamento real)

### DocumentType Enum

```php
// App\Enums\DocumentType
enum DocumentType: string
{
    case Prd = 'prd';
    case Spec = 'spec';
    case Decision = 'decision';
    case Note = 'note';
    case Reference = 'reference';

    public function label(): string
    {
        return match($this) {
            self::Prd => 'PRD',
            self::Spec => 'EspecificaÃ§Ã£o',
            self::Decision => 'DecisÃ£o',
            self::Note => 'Nota',
            self::Reference => 'ReferÃªncia',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::Prd => 'document-text',
            self::Spec => 'code-bracket',
            self::Decision => 'light-bulb',
            self::Note => 'pencil-square',
            self::Reference => 'book-open',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Prd => 'indigo',
            self::Spec => 'blue',
            self::Decision => 'amber',
            self::Note => 'zinc',
            self::Reference => 'emerald',
        };
    }
}
```

### Task â€” Campos Novos (Migration adicional)

```
Task (campos adicionados)
â”œâ”€â”€ session_prompt (longText nullable) â€” o prompt/spec para a sessÃ£o AI
â””â”€â”€ session_result (longText nullable) â€” resumo do que foi implementado
```

**Esses campos transformam a Task de "item de trabalho" para "sessÃ£o de desenvolvimento":**

- `session_prompt`: o dev escreve o prompt antes de iniciar a sessÃ£o Claude Code
- `session_result`: preenchido apÃ³s a sessÃ£o (manual ou via MCP `stop_timer`)
- Ambos suportam markdown e sÃ£o renderizados com o mesmo visualizador dos Documents

---

## 3. UI â€” Componentes e PÃ¡ginas

### 3.1 Visualizador Markdown (Componente ReutilizÃ¡vel)

O core da feature Ã© um componente que renderiza markdown como HTML formatado:

```blade
{{-- resources/views/components/âš¡markdown-viewer.blade.php --}}

{{-- Recebe: $content (string markdown), $title (string opcional) --}}
{{-- Renderiza: HTML formatado com Tailwind Typography --}}
```

**CaracterÃ­sticas:**

- Usa `Str::markdown($content)` do Laravel (CommonMark)
- EstilizaÃ§Ã£o via Tailwind Typography: classe `prose prose-invert` (dark mode)
- Syntax highlighting para blocos de cÃ³digo: highlight.js (CDN) com tema dark
- BotÃ£o "Copiar Markdown" (copia o raw markdown, nÃ£o o HTML)
- BotÃ£o "Copiar HTML" (copia o HTML renderizado)
- Header com tÃ­tulo + badge de tipo (se Document) + botÃ£o editar
- Scroll interno se conteÃºdo excede viewport
- Responsivo: full-width em tela grande, scroll vertical em tela pequena

**Onde Ã© usado:**

- PÃ¡gina de Document (visualizaÃ§Ã£o full)
- Modal de Document (preview rÃ¡pido)
- Task Modal â€” renderizaÃ§Ã£o de `session_prompt` e `session_result`
- Task Modal â€” renderizaÃ§Ã£o de `description` (melhoria sobre raw text atual)

### 3.2 PÃ¡gina de Documents por Projeto

AcessÃ­vel via tab "Docs" no detalhe do projeto (nova aba alÃ©m de Tasks | MÃ©tricas):

```
/projects/{slug}  â†’  Abas: Tasks | Docs | MÃ©tricas
```

**TambÃ©m existe pÃ¡gina global para documentos sem projeto:**

```
/docs  â†’  Lista todos os documentos (filtrÃ¡veis por projeto e tipo)
```

### 3.3 Editor de Document (PÃ¡gina Full)

```
/docs/{document-slug}/edit  â†’  Editor full-page com preview lado a lado
```

**Layout:**

- **Esquerda:** `<flux:editor>` com conteÃºdo markdown (ediÃ§Ã£o)
- **Direita:** Preview renderizado em tempo real (debounce 500ms)
- **Header:** TÃ­tulo editÃ¡vel + select de tipo + select de projeto + botÃ£o salvar
- **Footer:** Timestamps + contagem de palavras/caracteres

**Alternativa mais simples (se split-view for complexo demais):**

- Editor full-page com `<flux:editor>` e toggle "Preview" que alterna entre ediÃ§Ã£o e visualizaÃ§Ã£o
- BotÃ£o salvar com toast de confirmaÃ§Ã£o

### 3.4 Task Modal â€” Campos de SessÃ£o

Adicionar ao Task Modal existente (Epic 3, Task 3.2) duas novas seÃ§Ãµes colapsÃ¡veis:

```
Task Modal
â”œâ”€â”€ Campos existentes (tÃ­tulo, status, prioridade, etc.)
â”œâ”€â”€ DescriÃ§Ã£o (<flux:editor>)
â”œâ”€â”€ â–¼ Prompt da SessÃ£o       â† NOVO (colapsÃ¡vel, <flux:editor> para session_prompt)
â”œâ”€â”€ â–¼ Resultado da SessÃ£o    â† NOVO (colapsÃ¡vel, <flux:editor> para session_result)
â”œâ”€â”€ â–¼ Documentos Relacionados â† NOVO (lista de docs do projeto com links)
â”œâ”€â”€ TimeEntries (editÃ¡vel)
â””â”€â”€ Footer (salvar, deletar)
```

**Comportamento:**

- SeÃ§Ãµes "Prompt" e "Resultado" ficam **colapsadas por padrÃ£o** (nÃ£o ocupam espaÃ§o se vazias)
- Se o campo tem conteÃºdo, mostra preview renderizado (markdown â†’ HTML)
- Click no preview expande para ediÃ§Ã£o com `<flux:editor>`
- BotÃ£o "Visualizar" abre o markdown viewer em modal full-screen (estilo artefato)

### 3.5 Sidebar â€” Link para Docs

Adicionar item na sidebar:

```
Dashboard (chart-bar-square)
Inbox (inbox) + badge
Kanban (view-columns)
Daily (calendar-days)
Projetos (folder)
Docs (document-text)       â† NOVO
Tempo (clock)
```

---

## 4. MCP Context Endpoints

Esses endpoints servem conteÃºdo rico para ferramentas AI. Fazem parte do MCP Server (F4 do market research) mas o modelo de dados Ã© definido aqui.

### Endpoints de Documents

```
POST /mcp/tools/list_documents
  Params: project_slug? (filtrar por projeto), type? (filtrar por tipo)
  Response: [{ id, title, slug, type, project_slug, excerpt (primeiros 200 chars), updated_at }]

POST /mcp/tools/get_document
  Params: slug (ou id)
  Response: { id, title, slug, type, content (markdown completo), project, updated_at }

POST /mcp/tools/create_document
  Params: title, content, project_slug?, type?
  Response: { id, slug }

POST /mcp/tools/update_document
  Params: slug (ou id), title?, content?, type?
  Response: { id, slug, updated_at }
```

### Endpoints de Task Context (enriquecidos)

```
POST /mcp/tools/get_task_context
  Params: task_id
  Response: {
    task: { id, title, status, priority, description, session_prompt, session_result },
    project: { name, slug, description },
    project_documents: [{ title, slug, type, excerpt }],  â† docs do projeto da task
    time_entries: [{ started_at, stopped_at, duration_minutes, notes }],
    commits: [{ hash, message, created_at }]  â† se F2 (git) implementado
  }
```

**Caso de uso principal:**

```
Dev: "Vamos trabalhar na task #42"
Claude Code:
  1. get_task_context(42)
     â†’ Recebe: tÃ­tulo, prompt da sessÃ£o, PRD do projeto, specs relacionadas
  2. Tem contexto completo para implementar sem perguntas adicionais
```

### Endpoint de Contexto Completo do Projeto

```
POST /mcp/tools/get_project_context
  Params: project_slug
  Response: {
    project: { name, slug, description, status, priority },
    documents: [{ title, slug, type, content }],  â† todos os docs (conteÃºdo completo)
    active_tasks: [{ id, title, status, priority, session_prompt }],
    metrics: { total_hours, tasks_by_status, overdue_count }
  }
```

**Caso de uso:**

```
Dev: "Me dÃª uma visÃ£o geral do projeto API Gateway"
Claude Code:
  1. get_project_context("api-gateway")
     â†’ Recebe: PRD, specs, decisÃµes, tasks ativas, mÃ©tricas
  2. Pode sugerir prÃ³ximos passos, identificar gaps, priorizar
```

---

## 5. RenderizaÃ§Ã£o Markdown â€” ImplementaÃ§Ã£o TÃ©cnica

### DependÃªncias

```bash
# Tailwind Typography (jÃ¡ incluÃ­do no Tailwind CSS 4 como plugin)
# Verificar se @tailwindcss/typography estÃ¡ ativo

# highlight.js para syntax highlighting (via CDN)
# Incluir no layout ou lazy-load apenas nas pÃ¡ginas de documento
```

### Helper de RenderizaÃ§Ã£o

```php
// app/Support/Markdown.php (ou trait no model)

use Illuminate\Support\Str;

class Markdown
{
    public static function render(string $content): string
    {
        return Str::markdown($content, [
            'html_input' => 'strip',        // seguranÃ§a: strip HTML raw
            'allow_unsafe_links' => false,   // seguranÃ§a: bloquear javascript:
        ]);
    }

    public static function excerpt(string $content, int $length = 200): string
    {
        $plain = strip_tags(static::render($content));
        return Str::limit($plain, $length);
    }
}
```

### Classes CSS para o Viewer

```css
/* Incluir em resources/css/app.css ou como utility */

.markdown-viewer {
    @apply prose prose-invert prose-indigo max-w-none;
    /* prose-invert: dark mode */
    /* prose-indigo: links em indigo */
    /* max-w-none: sem limite de largura */
}

.markdown-viewer pre {
    @apply rounded-lg bg-zinc-900 border border-zinc-700;
}

.markdown-viewer code:not(pre code) {
    @apply bg-zinc-800 text-zinc-200 rounded px-1.5 py-0.5 text-sm;
}
```

### Syntax Highlighting

Usar highlight.js via CDN, carregado apenas quando hÃ¡ blocos `<pre><code>`:

```html
<!-- Lazy load apenas quando o componente markdown-viewer Ã© renderizado -->
<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css"
/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
    hljs.highlightAll();
</script>
```

**Nota:** Como SoloBoard Ã© local-only, o CDN Ã© aceitÃ¡vel. Alternativa: bundle via npm se preferir zero dependÃªncia externa.

---

## 6. Tasks para Claude Code

### Task 18.1 â€” Migration e Model: Document

```yaml
Prompt: |
    Crie a migration e o model Document com:
    - Fields: project_id (nullable FK cascade), title, slug, content (longText),
      type (string, default 'note'), is_pinned (boolean, default false),
      sort_order (integer, default 0)
    - PHP Enum: App\Enums\DocumentType (prd, spec, decision, note, reference)
      com mÃ©todos label() (PT-BR), icon(), color()
    - slug: unique scoped por project_id (unique globalmente se project_id null)
    - Slug auto-gerado do title via Str::slug()
    - Casts para enum
    - Scopes: forProject($projectId), global() (project_id null),
      pinned(), byType($type), ordered() (pinned desc, sort_order asc, title asc)
    - Relationships: belongsTo(Project)
    - Project: hasMany(Document) â€” adicionar ao model existente
    - Accessor: excerpt() â€” primeiros 200 chars do content (plain text)
    - Cascade delete: deletar projeto remove documents
    - Factory + seeder com 2-3 docs por projeto (PRD, spec, decisÃ£o)

Acceptance Criteria:
    - Migration roda sem erros
    - Slug Ã© unique por projeto
    - Enum com labels PT-BR
    - Scopes funcionam
    - Cascade delete funciona
    - Factory gera documents vÃ¡lidos
    - Teste Feature: criar document, verificar scopes, cascade

Arquivos:
    - database/migrations/*_create_documents_table.php
    - app/Models/Document.php
    - app/Enums/DocumentType.php
    - database/factories/DocumentFactory.php
    - app/Models/Project.php (adicionar relationship)
    - tests/Feature/DocumentTest.php

Commit: "feat: Document model with types, scoped slugs, and project relationship"
```

### Task 18.2 â€” Markdown Rendering para Session Fields (depende do Epic 15)

> **Nota:** Os campos `session_prompt` e `session_result` na Task sÃ£o criados pelo
> **Epic 15 (Task-as-Session), Task 15.1**. Esta task apenas adiciona renderizaÃ§Ã£o
> markdown nesses campos usando o componente `markdown-viewer` criado na Task 18.3.

```yaml
Prompt: |
    Usando os campos session_prompt e session_result jÃ¡ existentes na Task (Epic 15),
    adicione renderizaÃ§Ã£o markdown no Task Modal:

    - SeÃ§Ã£o "Prompt da SessÃ£o": renderizar session_prompt com <livewire:markdown-viewer />
    - SeÃ§Ã£o "Resultado da SessÃ£o": renderizar session_result com <livewire:markdown-viewer />
    - BotÃ£o "Expandir" em cada seÃ§Ã£o abre viewer full-screen (modal)
    - SeÃ§Ãµes colapsÃ¡veis (fechadas por padrÃ£o se vazias)

    Se Epic 15 ainda NÃƒO foi implementado:
    - Criar a migration e campos conforme Task 15.1 (session_prompt, session_result)
    - Marcar como "implementado antecipadamente para Epic 15"

Acceptance Criteria:
    - Session fields renderizam markdown formatado no Task Modal
    - BotÃ£o expandir abre viewer full-screen
    - Funciona com ou sem Epic 15 jÃ¡ implementado

Arquivos:
    - resources/views/components/âš¡task-modal.blade.php (atualizar)
    - tests/Feature/TaskSessionMarkdownTest.php

Commit: "feat: markdown rendering for Task session fields using markdown-viewer"
```

### Task 18.3 â€” Componente SFC: Markdown Viewer

```yaml
Prompt: |
    Crie o componente reutilizÃ¡vel de renderizaÃ§Ã£o Markdown:

    1. app/Support/Markdown.php:
       - render(string $content): string â€” Str::markdown() com config segura
       - excerpt(string $content, int $length = 200): string â€” texto plano truncado

    2. resources/views/components/âš¡markdown-viewer.blade.php (Livewire SFC):
       - Props: content (string markdown), title (string nullable), showCopyButtons (bool, default true)
       - Renderiza markdown como HTML com classe prose prose-invert
       - BotÃ£o "Copiar Markdown" (copia raw) via Alpine.js clipboard
       - BotÃ£o "Copiar HTML" (copia renderizado)
       - Syntax highlighting via highlight.js (CDN, lazy-loaded)
       - Dark mode styling

    3. resources/css/app.css:
       - Adicionar estilos .markdown-viewer (prose customizations para dark mode)

    4. Instalar/configurar @tailwindcss/typography se nÃ£o estiver ativo.

Acceptance Criteria:
    - Markdown renderiza corretamente (headings, code blocks, lists, links, bold, italic)
    - Blocos de cÃ³digo tÃªm syntax highlighting
    - BotÃµes de copiar funcionam
    - Dark mode visual consistente
    - Componente Ã© reutilizÃ¡vel (usado em docs e task modal)

Arquivos:
    - app/Support/Markdown.php
    - resources/views/components/âš¡markdown-viewer.blade.php
    - resources/css/app.css (atualizar)
    - tests/Feature/MarkdownViewerTest.php

Commit: "feat: reusable Markdown viewer component with syntax highlighting"
```

### Task 18.4 â€” PÃ¡gina SFC: Lista e VisualizaÃ§Ã£o de Documents

```yaml
Prompt: |
    Crie 2 SFCs para gestÃ£o de Documents:

    1. resources/views/pages/âš¡documents.blade.php (lista):
       - Filtros: projeto (<flux:select>), tipo (<flux:select>)
       - Grid de <flux:card>: Ã­cone do tipo + tÃ­tulo + badge tipo + projeto + excerpt + data
       - Documents pinned aparecem primeiro com indicador visual
       - Click â†’ navega para visualizaÃ§Ã£o
       - BotÃ£o "Novo Documento" abre modal de criaÃ§Ã£o
       - Empty state: "Nenhum documento. Crie seu primeiro PRD ou spec."

    2. resources/views/pages/âš¡document-view.blade.php (visualizaÃ§Ã£o full-page):
       - Header: tÃ­tulo + badge tipo + projeto + timestamps
       - BotÃµes: Editar, Fixar/Desafixar, Deletar (com confirmaÃ§Ã£o modal)
       - Body: <livewire:markdown-viewer :content="$document->content" />
       - BotÃ£o "Copiar para Clipboard" (raw markdown)
       - Breadcrumb: Docs > [Projeto] > [Documento]

    Rotas:
    - Route::livewire('/docs', 'pages.documents')->name('documents')
    - Route::livewire('/docs/{document:slug}', 'pages.document-view')->name('document.view')

    Adicionar "Docs" na sidebar com icon document-text (entre Projetos e Tempo).

Acceptance Criteria:
    - Lista renderiza com filtros funcionando
    - VisualizaÃ§Ã£o renderiza markdown formatado
    - Pinned documents aparecem primeiro
    - Copiar clipboard funciona
    - Deletar com confirmaÃ§Ã£o
    - Sidebar atualizada
    - Teste Feature: listar, visualizar, filtrar, deletar

Arquivos:
    - resources/views/pages/âš¡documents.blade.php
    - resources/views/pages/âš¡document-view.blade.php
    - resources/views/layouts/app.blade.php (sidebar â€” adicionar Docs)
    - routes/web.php
    - tests/Feature/DocumentListTest.php

Commit: "feat: Document list and full-page viewer with markdown rendering"
```

### Task 18.5 â€” PÃ¡gina SFC: Editor de Document

```yaml
Prompt: |
    Crie o SFC resources/views/pages/âš¡document-edit.blade.php:

    Modo: criar novo OU editar existente (mesma pÃ¡gina).

    Layout:
    - Header: <flux:input> para tÃ­tulo (wire:model.blur) + <flux:select> tipo +
      <flux:select> projeto (nullable, "Sem projeto") + <flux:checkbox> fixado
    - Body: <flux:editor> para conteÃºdo markdown (wire:model.live.debounce.1000ms)
    - Toggle "Preview": alterna entre editor e visualizaÃ§Ã£o renderizada
      - Preview usa <livewire:markdown-viewer />
    - Footer: BotÃ£o Salvar + toast confirmaÃ§Ã£o + "Ãšltima atualizaÃ§Ã£o: X"
    - Slug auto-gerado do tÃ­tulo (exibido como info, nÃ£o editÃ¡vel diretamente)

    Rotas:
    - Route::livewire('/docs/new', 'pages.document-edit')->name('document.create')
    - Route::livewire('/docs/{document:slug}/edit', 'pages.document-edit')->name('document.edit')

    ValidaÃ§Ã£o:
    - title: required, max:255
    - content: required
    - type: required, enum
    - Slug unique scoped por project_id

Acceptance Criteria:
    - Criar novo documento funciona
    - Editar existente carrega dados
    - Toggle preview renderiza markdown
    - Auto-save com debounce funciona
    - Slug auto-gerado
    - ValidaÃ§Ã£o com mensagens PT-BR
    - Teste Feature: criar, editar, validaÃ§Ã£o

Arquivos:
    - resources/views/pages/âš¡document-edit.blade.php
    - routes/web.php
    - tests/Feature/DocumentEditTest.php

Commit: "feat: Document editor SFC with preview toggle and auto-save"
```

### Task 18.6 â€” Integrar Documents no Project Detail e Task Modal

```yaml
Prompt: |
    Integre Documents nas pÃ¡ginas existentes:

    1. Project Detail (âš¡project-detail.blade.php):
       - Adicionar aba "Docs" (entre Tasks e MÃ©tricas): Tasks | Docs | MÃ©tricas
       - Tab Docs: lista documents do projeto (pinned primeiro)
       - BotÃ£o "Novo Documento" (pre-seleciona projeto)
       - Click no doc â†’ navega para visualizaÃ§Ã£o

    2. Task Modal (âš¡task-modal.blade.php):
       - Adicionar seÃ§Ã£o colapsÃ¡vel "Prompt da SessÃ£o" apÃ³s descriÃ§Ã£o
         - Se vazio: botÃ£o "Adicionar prompt"
         - Se preenchido: preview markdown renderizado (truncado 5 linhas)
         - Click expande para ediÃ§Ã£o com <flux:editor>
         - BotÃ£o "Expandir" abre markdown viewer full-screen
       - Adicionar seÃ§Ã£o colapsÃ¡vel "Resultado da SessÃ£o" (mesmo padrÃ£o)
       - Adicionar seÃ§Ã£o "Documentos do Projeto" (se task tem projeto):
         - Lista compacta: Ã­cone tipo + tÃ­tulo (link para doc)
         - MÃ¡ximo 5 docs, ordenados por pinned + tipo

    3. Quick-Add (âš¡task-quick-add.blade.php):
       - Sem alteraÃ§Ãµes (manter simplicidade do quick-add)

Acceptance Criteria:
    - Tab Docs aparece no Project Detail com documents do projeto
    - Task Modal mostra session_prompt/result como markdown renderizado
    - SeÃ§Ãµes colapsÃ¡veis funcionam
    - Links para documents do projeto aparecem no Task Modal
    - Teste Feature: verificar integraÃ§Ã£o

Arquivos:
    - resources/views/pages/âš¡project-detail.blade.php (atualizar)
    - resources/views/components/âš¡task-modal.blade.php (atualizar)
    - tests/Feature/DocumentIntegrationTest.php

Commit: "feat: integrate Documents in Project Detail tabs and Task Modal session fields"
```

---

## 7. Seed Realista (Atualizar Task 9.3)

Adicionar ao DatabaseSeeder existente:

```php
// Documents de exemplo
// Projeto "API Gateway":
//   - PRD: "PRD â€” API Gateway v2" (markdown com contexto, requisitos, decisÃµes)
//   - Spec: "Spec â€” AutenticaÃ§Ã£o OAuth2" (markdown tÃ©cnico)
//   - Decision: "DecisÃ£o â€” REST vs GraphQL" (markdown com prÃ³s/contras)

// Projeto "Landing Page v2":
//   - PRD: "PRD â€” Landing Page Redesign"
//   - Note: "ReferÃªncias visuais e inspiraÃ§Ãµes"

// Documento global (sem projeto):
//   - Reference: "ConvenÃ§Ãµes de CÃ³digo â€” SoloBoard"

// Tasks com session_prompt:
//   - Task doing: session_prompt preenchido com prompt detalhado
//   - Task done: session_prompt + session_result preenchidos
```

---

## 8. Ordem de ExecuÃ§Ã£o

```
Task 18.1: Document model + migration ............ ~15min
    â†“
Task 18.3: Markdown Viewer component ............. ~20min
    â†“ (18.2 depende de 18.3)
Task 18.2: Markdown rendering p/ session fields .. ~15min
    â†“
Task 18.4: Document list + view pages ............ ~30min
    â†“
Task 18.5: Document editor page .................. ~25min
    â†“
Task 18.6: IntegraÃ§Ã£o Project Detail + Task Modal  ~20min
```

**Total: ~2 horas de sessÃ£o Claude Code**

---

## 9. MCP Context â€” Resumo dos Endpoints

Esses endpoints serÃ£o implementados como parte do MCP Server (F4 do market research).
O modelo de dados desta Epic Ã© prÃ©-requisito.

| Endpoint              | Dados servidos                  | Caso de uso                               |
| --------------------- | ------------------------------- | ----------------------------------------- |
| `list_documents`      | TÃ­tulos + tipos + excerpts     | "Quais docs tem no projeto X?"            |
| `get_document`        | ConteÃºdo markdown completo     | "Leia o PRD do projeto X"                 |
| `get_task_context`    | Task + prompt + docs do projeto | "Contexto completo para task #42"         |
| `get_project_context` | Projeto + docs + tasks ativas   | "VisÃ£o geral do projeto X"               |
| `create_document`     | â€”                             | "Crie uma spec para a task #42"           |
| `update_document`     | â€”                             | "Atualize o PRD com as decisÃµes de hoje" |

**Fluxo ideal com MCP:**

```
Dev: "Vamos trabalhar na task #42 do SoloBoard"

Claude Code:
  1. get_task_context(42)
     â†’ { title: "Implementar autenticaÃ§Ã£o OAuth2",
         session_prompt: "Implemente OAuth2 com...",
         project_documents: [
           { title: "PRD â€” API Gateway v2", slug: "prd-api-gateway-v2" },
           { title: "Spec â€” AutenticaÃ§Ã£o OAuth2", slug: "spec-auth-oauth2" }
         ] }

  2. get_document("spec-auth-oauth2")
     â†’ { content: "# Spec â€” AutenticaÃ§Ã£o OAuth2\n\n## Requisitos\n..." }

  3. start_timer(42)
  4. update_task(42, status: doing)
  5. [... implementaÃ§Ã£o com contexto completo ...]
  6. stop_timer(42, notes: "OAuth2 implementado com refresh tokens")
  7. update_task(42, status: done, session_result: "Implementado...")
```

---

## 10. DecisÃµes de Design

| #   | DecisÃ£o               | Escolha                         | Alternativa descartada   |
| --- | ---------------------- | ------------------------------- | ------------------------ |
| 1   | Nesting de documents   | Flat (sem sub-pÃ¡ginas)         | Hierarquia tipo Notion   |
| 2   | Versionamento          | Sem versioning (git faz isso)   | Tabela de revisÃµes      |
| 3   | Editor                 | `<flux:editor>` (consistÃªncia) | CodeMirror / Monaco      |
| 4   | RenderizaÃ§Ã£o         | `Str::markdown()` + Typography  | Marked.js client-side    |
| 5   | Syntax highlight       | highlight.js via CDN            | Prism.js / Shiki         |
| 6   | Slug scope             | Unique por project_id           | Unique global            |
| 7   | Documents globais      | Permitir (project_id null)      | Obrigar projeto          |
| 8   | Session fields na Task | Campos diretos no model         | Tabela separada          |
| 9   | Preview no editor      | Toggle (editor â†” preview)     | Split-view lado a lado   |
| 10  | Docs no Quick-Add      | Sem alteraÃ§Ã£o (simplicidade)  | Sintaxe $doc para linkar |

---

## 11. AtualizaÃ§Ã£o do CLAUDE.md

Adicionar ao CLAUDE.md existente:

```markdown
## Documents (Pages)

- Model: App\Models\Document (project_id nullable, tipo enum, conteÃºdo markdown)
- Enum: App\Enums\DocumentType (prd, spec, decision, note, reference) com label() PT-BR
- RenderizaÃ§Ã£o: app/Support/Markdown.php â†’ Str::markdown() + Tailwind Typography (prose prose-invert)
- Syntax highlighting: highlight.js via CDN (tema github-dark)
- Editor: <flux:editor> (mesmo da descriÃ§Ã£o de tasks)
- Viewer: <livewire:markdown-viewer /> â€” componente reutilizÃ¡vel
- Slug: unique scoped por project_id
- Cascade delete: deletar projeto remove documents

## Task Session Fields

- session_prompt (longText) â€” prompt para sessÃ£o AI
- session_result (longText) â€” resultado da sessÃ£o
- Ambos renderizados como markdown no Task Modal (seÃ§Ãµes colapsÃ¡veis)
- MCP serve esses campos via get_task_context

## MCP Context (quando implementado)

- Documents acessÃ­veis via get_document / list_documents
- get_task_context inclui: task + session fields + project documents
- get_project_context inclui: project + all documents + active tasks
```
