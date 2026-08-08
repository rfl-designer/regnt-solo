# Development Workflow Prompt

> MCP Prompt que orquestra o workflow completo de desenvolvimento para implementar uma task no SoloBoard.

## Visão Geral

O `DevelopmentWorkflowPrompt` guia o desenvolvedor através de um workflow estruturado de 5 fases, integrando todas as ferramentas do SoloBoard MCP e ativando skills conforme necessidade.

**Localização:** `app/Mcp/Prompts/DevelopmentWorkflowPrompt.php`

## Uso

```
development-workflow task_id=123
```

### Argumento

| Nome | Tipo | Obrigatório | Descrição |
|------|------|-------------|-----------|
| `task_id` | integer | Sim | ID da task a implementar |

## Contexto Gerado

O prompt monta 5 seções de contexto automaticamente:

### 1. Task Context (`buildTaskContext`)

Dados completos da task:
- ID, título, status, prioridade
- Due date com indicador de urgência (🔴 ATRASADA / 🟡 URGENTE / 🟢 OK)
- Estimativa e tempo já trabalhado
- Descrição e session_prompt (User Story)
- Session result (trabalho anterior)
- Commits existentes (últimos 5)
- PR URL

### 2. Project Context (`buildProjectContext`)

Informações do projeto:
- Nome com emoji
- Descrição e status
- Slug para uso em tools
- Contagem de tasks ativas (doing/todo/backlog)

### 3. Related Documents (`buildRelatedDocuments`)

Documentos do projeto (até 5):
- PRDs, Specs, Decisions
- Ordenados por pinned e updated_at
- Slugs para uso com `get-document`

### 4. Related Tasks (`buildRelatedTasks`)

Tasks do mesmo projeto (até 5):
- Status doing/todo
- Ordenadas por status e prioridade
- Badge 🤖 para session tasks

### 5. Today Context (`buildTodayContext`)

Contexto do dia:
- Data atual
- Timer ativo (com aviso se será parado)
- Progresso do plano do dia
- Horas trabalhadas hoje

## Workflow em 5 Fases

### 📥 1. INTAKE E ANÁLISE

```
1. start-timer task_id=123
2. update-task task_id=123 status=doing
3. git checkout main && git pull
4. git checkout -b feature/nome-da-task
```

Ações:
- Confirmar que a task foi lida e compreendida
- Iniciar timer imediatamente
- Atualizar status para "doing"
- Verificar branch correta
- Ler documentos relacionados (PRD/Spec)
- Listar perguntas se spec não estiver clara

### 📋 2. PLANEJAMENTO

Ações:
- Explorar código existente (Models, Policies, Migrations, Componentes)
- Definir arquitetura e abordagem
- Confirmar o contexto do dia com `get-ritual-status` (ritual feito? o que está esperando?)
- Conferir a ordem da fila com `get-pull-queue` antes de puxar mais trabalho
- Criar branch feature/nome-descritivo

### ⚡ 3. IMPLEMENTAÇÃO

Ordem de execução:
1. Migration + Model (casts, relationships, scopes)
2. Policy / Authorization
3. Action / Service (lógica de negócio isolada)
4. Componentes Livewire
5. UI com Flux

**Skills ativadas conforme necessidade** (ver seção abaixo)

### ✅ 4. QUALIDADE

Checklist:
- [ ] Validação: Rules Livewire + Constraints DB
- [ ] Error Handling: Flash + wire:loading
- [ ] Testes obrigatórios (pest-testing)
- [ ] Verificar N+1 queries
- [ ] Verificar componente pesado
- [ ] Verificar SRP violado

### 🚀 5. ENTREGA

```
1. php artisan test --compact
2. vendor/bin/pint --dirty
3. git add . && git commit -m "feat: título da task"
4. log-commits task_id=123 commits=[...] pr_url=...
5. update-task task_id=123 status=done session_result="..."
6. stop-timer task_id=123 notes="..."
```

## Detecção Automática de Skills

O método `detectRequiredSkills()` analisa título + descrição + session_prompt e sugere skills:

| Skill | Keywords detectadas |
|-------|---------------------|
| `livewire-development` | livewire, wire:, componente, component, reativo, reactive, real-time |
| `fluxui-development` | flux:, modal, form, input, button, table, chart, date-picker, ui component |
| `tailwindcss-development` | estilo, style, layout, grid, flex, dark mode, responsive, css, design |
| `pest-testing` | test, teste, spec, tdd, coverage, pest (**sempre obrigatório**) |
| `developing-with-fortify` | login, logout, auth, password, registro, register, 2fa, verification |
| `mcp-development` | mcp, tool, resource, prompt, ai server, routes/ai |

## Skills Disponíveis

| Skill | Descrição |
|-------|-----------|
| `livewire-development` | Componentes reativos Livewire v4 |
| `fluxui-development` | UI com Flux UI Pro |
| `pest-testing` | Testes com Pest 4 |
| `tailwindcss-development` | Estilos com Tailwind CSS v4 |
| `mcp-development` | Servidores MCP |
| `developing-with-fortify` | Autenticação Laravel Fortify |

## Regras do Workflow

1. **NUNCA** pular a fase de testes
2. **SEMPRE** iniciar timer antes de começar
3. **SEMPRE** parar timer com notas ao finalizar
4. **SEMPRE** registrar commits e PR URL
5. Ativar skills conforme necessidade da feature
6. Commits em inglês, mensagens de UI em português

## Exemplo de Saída

Para uma task com ID 42:

```markdown
## 📋 Task para Implementar
- **ID**: 42
- **Título**: Adicionar filtro por data no Kanban
- **Status atual**: Todo
- **Prioridade**: Alta
- **Due date**: 2024-02-20 (🟡 URGENTE)
- **Estimativa**: 2h

### Session Prompt (User Story)
## User Story
Como desenvolvedor, quero filtrar tasks por data no Kanban...

## 📁 Projeto: 📋 SoloBoard
- **Status**: Ativo
- **Slug**: `soloboard`
- **Tasks ativas**: 2 doing, 5 todo, 8 backlog

## 📄 Documentos Relacionados
- 📌 [prd] **SoloBoard PRD** (slug: `soloboard-prd`)
- [spec] **Kanban Spec** (slug: `kanban-spec`)

## ⏰ Contexto de Hoje
- **Data**: 19/02/2024 (Monday)
- **Timer ativo**: Nenhum
- **Plano do dia**: 2/5 tasks completadas
- **Horas trabalhadas hoje**: 3.5h

## 🚀 Próximos Passos

### 1️⃣ INTAKE (execute agora)
1. start-timer task_id=42
2. update-task task_id=42 status=doing
3. git checkout main && git pull
4. git checkout -b feature/adicionar-filtro-por-data-no-kanban

### 2️⃣ PLANEJAMENTO
- Explorar Models, Migrations, Policies existentes
- Definir arquivos que serão criados/modificados

### 3️⃣ SKILLS A ATIVAR
- 🔧 `livewire-development` - Componentes reativos detectados
- 🔧 `fluxui-development` - UI components detectados
- 🔧 `pest-testing` - **Sempre obrigatório**
```

## Tools do SoloBoard Utilizadas

| Tool | Fase | Uso |
|------|------|-----|
| `start-timer` | Intake | Iniciar cronômetro |
| `update-task` | Intake/Entrega | Mudar status para doing/done |
| `get-ritual-status` | Planejamento | Estado do ritual matinal e do quadro |
| `get-pull-queue` | Planejamento | Ordem da fila de puxar (Pronto) |
| `get-document` | Planejamento | Ler PRDs/Specs |
| `log-commits` | Entrega | Registrar commits e PR |
| `stop-timer` | Entrega | Parar timer com notas |
| `get-analytics` | Entrega | Verificar métricas |

## Integração com Task-as-Session

Se a task tem `session_prompt` preenchido (é uma session task):
- O prompt é exibido na seção "Session Prompt (User Story)"
- O resultado anterior é exibido em "Session Result"
- Ao finalizar, usar `session_result` no `update-task` para documentar o que foi feito
