# EPIC 13: Integração Git (Commits → Task)

> **Sessão Claude Code:** Conectar commits e PRs às tasks.
> **Prioridade:** #5 (alto impacto, esforço médio)
> **Estimativa:** ~1h · 3 tasks
> **Dependência:** Epic 10 (MCP) — log_commits via MCP é o canal principal

---

## Contexto

No workflow agentic, uma task = uma sessão = um PR. O PR é o artefato revisável. Nenhuma ferramenta solo local faz a ponte entre git e PM. O SoloBoard faz isso via MCP tool (`log-commits`) e git hooks opcionais.

---

## Task 13.1 — Model TaskCommit + MCP Tool

```yaml
Prompt: |
    Crie model para associar commits a tasks:

    Migration: task_commits
    - id, task_id (FK cascade), hash (string, unique), message (text),
      files_changed (integer default 0), insertions (integer default 0),
      deletions (integer default 0), committed_at (datetime)
    - timestamps

    Campo na Task: pr_url (string nullable)
    - Migration: add_pr_url_to_tasks

    Model: TaskCommit
    - Fillable, casts
    - Relationship: belongsTo(Task)
    - Scope: forTask($taskId), recent($limit)

    Task model:
    - hasMany(TaskCommit)
    - commitCount(): int
    - totalFilesChanged(): int

    MCP Tool: LogCommitsTool (log-commits)
    - Input schema:
      - task_id (integer, required)
      - commits (array, required): cada item com hash, message, files_changed,
        insertions, deletions, committed_at
      - pr_url (string, optional)
    - Registra commits e opcionalmente atualiza pr_url da task
    - Ignora commits com hash duplicado (upsert)
    - Retorna: task com commits registrados

    Registrar no SoloBoardServer.

Acceptance Criteria:
    - Commits associados a tasks corretamente
    - Duplicatas ignoradas (por hash)
    - pr_url atualizado quando informado
    - MCP tool funciona end-to-end
    - Cascade delete: deletar task remove commits
    - Teste Feature: log commits via MCP, verificar DB

Arquivos:
    - database/migrations/*_create_task_commits_table.php
    - database/migrations/*_add_pr_url_to_tasks_table.php
    - app/Models/TaskCommit.php
    - app/Models/Task.php (atualizar)
    - app/Mcp/Tools/LogCommitsTool.php
    - app/Mcp/Servers/SoloBoardServer.php (atualizar)
    - tests/Feature/Mcp/LogCommitsToolTest.php

Commit: "feat: TaskCommit model and MCP log-commits tool"
```

---

## Task 13.2 — Git Hooks (artisan command)

````yaml
Prompt: |
    Crie artisan command para instalar git hooks:

    app/Console/Commands/GitHookInstallCommand.php (soloboard:git-hook install):
    - Argumento: path do repositório (default: diretório atual)
    - Instala hook post-commit em {repo}/.git/hooks/post-commit
    - O hook:
      1. Lê mensagem do último commit
      2. Extrai referência à task: [SB-{id}] ou #SB-{id} no message
      3. Se encontrou: faz POST para o MCP do SoloBoard com o commit info
         (usa curl com SOLOBOARD_MCP_KEY e SOLOBOARD_URL do ambiente)
    - Flag --url para definir URL do SoloBoard (default: http://soloboard.test)
    - Flag --key para definir API key

    O hook é um script bash que:
    ```bash
    #!/bin/bash
    COMMIT_MSG=$(git log -1 --pretty=%B)
    TASK_ID=$(echo "$COMMIT_MSG" | grep -oP '\[SB-\K\d+' | head -1)
    if [ -n "$TASK_ID" ]; then
      HASH=$(git rev-parse HEAD)
      FILES=$(git diff --numstat HEAD~1 HEAD | wc -l)
      INSERTIONS=$(git diff --numstat HEAD~1 HEAD | awk '{s+=$1}END{print s}')
      DELETIONS=$(git diff --numstat HEAD~1 HEAD | awk '{s+=$2}END{print s}')
      # POST to SoloBoard MCP...
    fi
````

Também crie soloboard:git-hook remove para desinstalar.

Acceptance Criteria:

- Hook instalado corretamente no repo
- Extrai [SB-{id}] da mensagem do commit
- POST para MCP com dados do commit
- Não interfere se não encontra referência
- soloboard:git-hook remove desinstala
- Teste: instalação e remoção do hook

Arquivos:

- app/Console/Commands/GitHookInstallCommand.php
- app/Console/Commands/GitHookRemoveCommand.php
- tests/Feature/GitHookTest.php

Commit: "feat: artisan commands to install/remove git hooks for commit tracking"

````

---

## Task 13.3 — Visualização de commits no Task Modal + Kanban

```yaml
Prompt: |
  Adicione visualização de commits e PR:

  1. No Task Modal (⚡task-modal.blade.php):
  - Nova seção "Git" entre TimeEntries e "Tempo por status"
  - Campo editável: PR URL (input com ícone de link externo)
  - Lista de commits: hash (6 chars), mensagem (truncada), files changed,
    +insertions/-deletions, data
  - Se tem PR URL: botão "Abrir PR" (link externo)
  - Se não tem commits: "Nenhum commit registrado"

  2. No Kanban Card:
  - Badge compacto com número de commits (ex: "3 commits") se > 0
  - Ícone git-branch sutil

  3. No Project Detail (tab Métricas):
  - Total de commits por projeto
  - Total de files changed

Acceptance Criteria:
  - Commits listados no Task Modal
  - PR URL editável e salva
  - Badge de commits no Kanban card
  - Métricas de git no projeto
  - Teste Feature: verificar visualização

Arquivos:
  - resources/views/components/⚡task-modal.blade.php (atualizar)
  - resources/views/pages/⚡kanban.blade.php (atualizar)
  - resources/views/pages/⚡project-detail.blade.php (atualizar)
  - tests/Feature/GitVisualizationTest.php

Commit: "feat: git commits and PR visualization in Task Modal and Kanban"
````
