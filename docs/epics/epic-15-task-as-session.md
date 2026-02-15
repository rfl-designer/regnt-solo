# EPIC 15: Task-as-Session (Workflow Agentic)

> **Sessão Claude Code:** Modelar tasks como sessões de AI coding.
> **Prioridade:** #7 (diferenciação única)
> **Estimativa:** ~45min · 2 tasks
> **Dependência:** Epic 10 (MCP), Epic 13 (Git Integration)

---

## Contexto

Para devs usando Claude Code/Cursor/Copilot, uma "task" é essencialmente uma "sessão de AI coding" que resulta em um PR. Nenhuma ferramenta PM modela esse workflow. SoloBoard seria a primeira.

**Fluxo completo: 1 task = 1 sessão = 1 PR**

1. Dev cria task com prompt no campo `session_prompt`
2. Inicia timer (+ focus mode opcional) via MCP
3. Claude Code lê o prompt e executa
4. Commits registrados automaticamente via MCP/hooks
5. Ao finalizar: PR url e resultado registrados
6. Task move para done
7. Task modal mostra: prompt → commits → PR → resultado

---

## Task 15.1 — Session fields na Task + Template

```yaml
Prompt: |
    Adicione campos de sessão à Task:

    Migration: add_session_fields_to_tasks
    - session_prompt (text nullable) — o prompt/spec dado ao AI
    - session_result (text nullable) — resumo do que foi implementado

    (pr_url já existe da Epic 13)

    Atualizar Task model:
    - isSessionTask(): bool — tem session_prompt preenchido
    - sessionSummary(): array — retorna {prompt, result, pr_url, commits, files_changed,
      total_time_minutes, focus_time_minutes}

    Atualizar Task Factory:
    - State: ->session() que preenche session_prompt com texto realista

    Atualizar Seed (DatabaseSeeder):
    - Algumas tasks "doing" e "done" com session_prompt e session_result preenchidos

    Atualizar MCP CreateTaskTool e UpdateTaskTool:
    - Aceitar session_prompt e session_result como campos opcionais
    - GetTaskTool: incluir session_prompt, session_result, sessionSummary no retorno

    Novo MCP Prompt: SessionPlanningPrompt
    - Description: "Reads a task's session prompt and helps plan the coding session"
    - Arguments: task_id (integer, required)
    - Retorna: system message com contexto da task, projeto, prompt de sessão
      e instruções para o AI planejar a implementação

Acceptance Criteria:
    - Campos de sessão persistem corretamente
    - isSessionTask() identifica tasks com prompt
    - MCP tools aceitam e retornam campos de sessão
    - SessionPlanningPrompt gera contexto útil
    - Teste Feature: criar task com session_prompt, verificar via MCP

Arquivos:
    - database/migrations/*_add_session_fields_to_tasks_table.php
    - app/Models/Task.php (atualizar)
    - database/factories/TaskFactory.php (atualizar)
    - database/seeders/DatabaseSeeder.php (atualizar)
    - app/Mcp/Tools/CreateTaskTool.php (atualizar)
    - app/Mcp/Tools/UpdateTaskTool.php (atualizar)
    - app/Mcp/Tools/GetTaskTool.php (atualizar)
    - app/Mcp/Prompts/SessionPlanningPrompt.php
    - app/Mcp/Servers/SoloBoardServer.php (atualizar)
    - tests/Feature/TaskSessionTest.php

Commit: "feat: session fields on Task with MCP integration and planning prompt"
```

---

## Task 15.2 — Session View no Task Modal

```yaml
Prompt: |
    Adicione visualização de sessão no Task Modal:

    No Task Modal (⚡task-modal.blade.php):
    - Se isSessionTask(): mostrar layout especial "Sessão de Desenvolvimento"
    - Seção "Prompt" (colapsável, aberta por padrão se task não está done):
      - <flux:editor> com session_prompt (editável se task não está done, read-only se done)
      - Label: "Prompt da Sessão"
    - Seção "Resultado" (aparece só quando session_result preenchido):
      - <flux:editor> com session_result (editável)
      - Label: "Resultado da Sessão"
    - Seção "Timeline da Sessão" (visual):
      - Fluxo horizontal: Prompt → Timer (duração) → Commits (N) → PR → Done
      - Cada etapa com ícone e status (completo/pendente)
      - Se tem PR: link clicável para pr_url
      - Se tem commits: contador com tooltip listando commits
      - Se tem focus sessions: badge "🎯 Xh de focus"

    No Quick-Add (⚡task-quick-add.blade.php):
    - Novo atalho de prefixo: >prompt no final do título
      abre campo expandido para session_prompt
    - Ou: checkbox "Sessão de Dev" que mostra textarea extra

    No Kanban Card:
    - Badge sutil "🤖" ou ícone de terminal para tasks com session_prompt

Acceptance Criteria:
    - Task Modal detecta e mostra layout de sessão
    - Prompt e resultado editáveis com Flux Editor
    - Timeline visual mostra progresso da sessão
    - Quick-Add permite criar task de sessão
    - Kanban badge identifica session tasks
    - Teste Feature: criar session task, verificar modal

Arquivos:
    - resources/views/components/⚡task-modal.blade.php (atualizar)
    - resources/views/components/⚡task-quick-add.blade.php (atualizar)
    - resources/views/pages/⚡kanban.blade.php (atualizar)
    - tests/Feature/SessionViewTest.php

Commit: "feat: session view in Task Modal with timeline and prompt editor"
```
