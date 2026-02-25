# Ralph Loop

> Loop autônomo de AI coding que transforma Features em implementação completa, uma user story por vez.

## O que é

Ralph é um loop que orquestra agentes de AI (Claude Code, Codex) para implementar Features de forma 100% autônoma. Cada Feature é exportada como um PRD (Product Requirements Document) com user stories, e o agente implementa uma story por iteração até completar todas.

**Um ciclo:**
1. Feature com tasks no SoloBoard -> Exporta como PRD + instruções
2. AI lê PRD e progress.txt (padrões de iterações anteriores)
3. AI implementa UMA user story
4. AI roda testes + linter, commita, atualiza PRD (`passes: true`)
5. AI registra progresso e learnings
6. Script verifica se há stories pendentes e inicia nova iteração

## Pré-requisitos

- [SoloBoard](https://github.com/your-org/soloboard) com MCP Server configurado
- Claude Code CLI (`claude`) ou Codex CLI (`codex`)
- Feature criada no SoloBoard com tasks (cada task = uma user story)
- Tasks com `session_prompt` preenchido (template de User Story com critérios de aceitação)

## Ambiente de Desenvolvimento (DevContainer)

O Ralph roda dentro de um DevContainer que já inclui todas as dependências. Esta configuração é padrão para todos os projetos e garante que o ambiente seja reproduzível.

### Estrutura

```
.devcontainer/
  devcontainer.json       # Configuração principal
  Dockerfile              # Imagem PHP 8.4 + Node 22 + extensões
  docker-compose.yml      # App + PostgreSQL 17 + Redis 7
  scripts/
    post-create.sh        # Setup inicial (composer, npm, migrations)
    post-start.sh         # Instala/atualiza Claude Code CLI
```

### O que o container inclui

| Componente | Versão | Propósito |
|------------|--------|-----------|
| PHP | 8.4 (cli-bookworm) | Runtime Laravel |
| Node.js | 22 LTS | Vite, Tailwind, Claude Code CLI |
| PostgreSQL | 17 Alpine | Banco de dados |
| Redis | 7 Alpine | Cache, sessão, filas |
| Composer | 2 | Dependências PHP |
| Claude Code | latest | AI agent para o Ralph loop |
| Oh My Zsh | latest | Shell com DX otimizada |

### Mounts e variáveis de ambiente

O `devcontainer.json` monta o diretório `~/.claude` do host no container, permitindo que o Claude Code use a mesma configuração e autenticação do ambiente local:

```json
{
  "mounts": [
    "source=${localEnv:HOME}/.claude,target=/home/dev/.claude,type=bind,consistency=cached"
  ],
  "containerEnv": {
    "ANTHROPIC_API_KEY": "${localEnv:ANTHROPIC_API_KEY}",
    "CLAUDE_CONFIG_DIR": "/home/dev/.claude"
  }
}
```

Variáveis que devem estar no ambiente do host:

| Variável | Obrigatória | Descrição |
|----------|-------------|-----------|
| `ANTHROPIC_API_KEY` | Sim | Chave da API Anthropic (usada pelo Claude Code) |
| `COMPOSER_AUTH` | Sim* | Autenticação Composer (pacotes privados) |
| `FLUX_EMAIL` | Sim* | Email da licença Flux UI Pro |
| `FLUX_LICENSE_KEY` | Sim* | Chave da licença Flux UI Pro |

*Obrigatória para projetos que usam Flux UI Pro.

### Lifecycle scripts

**`post-create.sh`** (roda na criação do container):
1. Configura autenticação Flux Pro via `composer config`
2. `composer install` com autoloader otimizado
3. `npm install`
4. Copia `.env.devcontainer` ou `.env.example`
5. `php artisan key:generate`
6. `php artisan migrate`
7. `php artisan storage:link`
8. Limpa caches (config, route, view)

**`post-start.sh`** (roda a cada start do container):
1. Instala ou verifica Claude Code CLI (`curl -fsSL https://claude.ai/install.sh | bash`)
2. Limpa caches stale do Laravel

### Portas expostas

| Porta interna | Porta host | Serviço |
|---------------|------------|---------|
| 8000 | 8001 | Laravel (`php artisan serve`) |
| 5173 | 5174 | Vite (`npm run dev`) |

### Replicando para outro projeto

Copie o diretório `.devcontainer/` e adapte:

1. **Dockerfile** — ajuste extensões PHP e versão do Node conforme seu stack
2. **docker-compose.yml** — troque PostgreSQL por MySQL se necessário, ajuste portas
3. **post-create.sh** — adapte o setup inicial (seeder, migrations, etc.)
4. **post-start.sh** — mantenha a instalação do Claude Code CLI
5. **devcontainer.json** — ajuste mounts e variáveis de ambiente

O ponto crítico é o mount de `~/.claude` — sem ele, o Claude Code no container não terá acesso à configuração e MCP servers do host.

## Quick Start

```bash
# 1. Exportar Feature como Ralph files
php artisan soloboard:ralph <feature_id> --max-iterations=10

# 2. Rodar o loop autônomo
./scripts/ralph.sh --tool claude 10
```

## Estrutura de Arquivos

```
storage/ralph/
  prd.json          # PRD com user stories (gerado pelo comando)
  CLAUDE.md         # Instruções para o agente AI (gerado do stub)
  progress.txt      # Log de progresso (preenchido pelo AI)
  archive/          # Backups timestamped de runs anteriores
    20260225_190544/
      prd.json
      progress.txt

scripts/
  ralph.sh          # Shell script do loop

stubs/
  ralph-claude.md.stub  # Template das instruções para o AI
```

## Componentes

### 1. Comando Artisan — `soloboard:ralph`

Exporta uma Feature do SoloBoard como arquivos Ralph.

```bash
php artisan soloboard:ralph <feature_id> [--max-iterations=10] [--dry-run] [--output-dir=storage/ralph]
```

| Opção | Default | Descrição |
|-------|---------|-----------|
| `feature_id` | (obrigatório) | ID da Feature no SoloBoard |
| `--max-iterations` | 10 | Máximo de iterações do loop |
| `--dry-run` | false | Mostra o que seria gerado sem criar arquivos |
| `--output-dir` | `storage/ralph` | Diretório de saída |

**Gera 3 arquivos:**
- `prd.json` — PRD estruturado com user stories mapeadas das tasks
- `CLAUDE.md` — Instruções completas para o agente AI (gerado do stub)
- `progress.txt` — Arquivo vazio (preenchido iterativamente pelo AI)

### 2. Shell Script — `ralph.sh`

Loop principal que orquestra as iterações do agente AI.

```bash
./scripts/ralph.sh [--tool claude|codex] [max_iterations]
```

**Fluxo:**
1. Valida que `prd.json` e `CLAUDE.md` existem
2. Arquiva run anterior se `progress.txt` não está vazio
3. Loop de 1 a N iterações:
   - Verifica se há stories com `passes: false`
   - Se todas passam -> sai com sucesso
   - Executa o AI tool passando `CLAUDE.md` como input
   - Verifica sinal `<promise>COMPLETE</promise>` na saída
   - Pausa de 2s entre iterações
4. Se atingiu max iterações -> sai com falha

**Sem dependência de `jq`** — usa PHP inline para parsear JSON.

### 3. Template — `ralph-claude.md.stub`

Template Blade-like com placeholders `{{ $projectName }}`, `{{ $featureTitle }}`, `{{ $taskList }}`.

Define as instruções para o AI em 11 passos:
1. Ler PRD e progress.txt
2. Escolher story de maior prioridade com `passes: false`
3. Iniciar timer via MCP
4. Atualizar status para `doing` via MCP
5. Implementar a story
6. Rodar testes e linter
7. Commitar com conventional commits
8. Atualizar SoloBoard via MCP (status, timer, session_result)
9. Marcar `passes: true` no prd.json
10. Registrar progresso e learnings no progress.txt
11. Se todas passam -> responder com `<promise>COMPLETE</promise>`

### 4. MCP Tool — `ralph-export`

Expõe o export como ferramenta MCP para uso direto por AI clients.

```
ralph-export feature_id=5 max_iterations=10
```

Retorna o JSON do PRD sem criar arquivos — útil para inspeção.

## Formato do PRD

```json
{
  "project": "Nome da Feature",
  "branchName": "ralph/slug-da-feature",
  "description": "Spec completa da feature (markdown)",
  "maxIterations": 10,
  "userStories": [
    {
      "id": "US-166",
      "title": "feat: Kanban due date filter",
      "description": "User story completa com contexto e notas técnicas",
      "acceptanceCriteria": [
        "Critério 1",
        "Critério 2"
      ],
      "priority": 2,
      "passes": false,
      "metadata": {
        "soloboard_task_id": 166,
        "soloboard_status": "todo"
      }
    }
  ]
}
```

**Mapeamento:**
- `priority`: 1=Urgent, 2=High, 3=Medium, 4=Low
- `passes`: `true` se task está Done, `false` caso contrário
- `acceptanceCriteria`: extraído automaticamente da seção "Critérios de Aceitação" do `session_prompt`

## Formato do progress.txt

O AI preenche iterativamente. Estrutura:

```markdown
## Codebase Patterns
- Padrão reusável 1 (consolidado de múltiplas iterações)
- Padrão reusável 2
---

## 2026-02-25 19:06 - US-166
- O que foi implementado
- Arquivos alterados
- **Learnings for future iterations:**
  - Padrões descobertos
  - Gotchas encontrados
  - Contexto útil
---

## 2026-02-25 20:15 - US-167
- ...
---
```

**Seção "Codebase Patterns"** fica no topo e é consultada primeiro por cada iteração. Contém padrões gerais e reusáveis (não detalhes de stories específicas). Isso cria um **efeito de aprendizado cumulativo** — cada iteração se beneficia das anteriores.

## Integração com SoloBoard MCP

Durante cada iteração, o AI usa estas ferramentas MCP:

| Tool | Quando | Para quê |
|------|--------|----------|
| `start-timer` | Início | Iniciar cronômetro da task |
| `update-task` | Início | Mudar status para `doing` |
| `update-task` | Fim | Mudar status para `done`, preencher `session_result` |
| `stop-timer` | Fim | Parar timer com notas do que foi feito |
| `get-task` | Quando necessário | Consultar detalhes da task |

## Template de User Story (session_prompt)

Para Ralph funcionar bem, cada task deve ter um `session_prompt` estruturado:

```markdown
## User Story
Como [persona], quero [ação] para [benefício].

## Contexto
[Situação atual, arquivos relevantes, linhas de código]

## Critérios de Aceitação
- [ ] Critério 1
- [ ] Critério 2
- [ ] Critério 3

## Notas Técnicas
[Arquivos, padrões existentes, snippets de referência]
```

Quanto mais detalhado o `session_prompt`, melhor o resultado. Inclua:
- Nomes de arquivos e números de linha
- Snippets de código como referência de padrão
- Exemplos de implementação similar existente

## Replicando em Outro Repositório

Para usar Ralph em um projeto diferente:

### 1. Copie os arquivos base

```
stubs/ralph-claude.md.stub    # Template de instruções (adapte ao seu projeto)
scripts/ralph.sh              # Loop script (genérico, funciona em qualquer projeto)
```

### 2. Adapte o stub

Edite `stubs/ralph-claude.md.stub` para refletir:
- Quality checks do seu projeto (`npm test`, `cargo test`, etc.)
- Ferramentas de lint (`eslint`, `rustfmt`, etc.)
- Formato de commit
- Ferramentas MCP disponíveis (ou remova a seção MCP se não usar)

### 3. Gere o PRD manualmente (sem SoloBoard)

Se não usar SoloBoard, crie `storage/ralph/prd.json` manualmente seguindo o formato:

```json
{
  "project": "Nome do Projeto",
  "branchName": "ralph/nome-da-feature",
  "description": "Descrição da feature",
  "maxIterations": 10,
  "userStories": [
    {
      "id": "US-1",
      "title": "feat: primeira story",
      "description": "Descrição detalhada com contexto e notas técnicas",
      "acceptanceCriteria": ["Critério 1", "Critério 2"],
      "priority": 1,
      "passes": false,
      "metadata": {}
    }
  ]
}
```

### 4. Gere o CLAUDE.md

Popule o stub manualmente ou crie `storage/ralph/CLAUDE.md` diretamente.

### 5. Execute

```bash
# Crie progress.txt vazio
touch storage/ralph/progress.txt

# Rode o loop
chmod +x scripts/ralph.sh
./scripts/ralph.sh --tool claude 10
```

### Checklist de adaptação

- [ ] Adaptar quality checks no stub (testes + linter do seu projeto)
- [ ] Adaptar formato de commit (se não usar conventional commits)
- [ ] Remover/adaptar referências ao SoloBoard MCP (se não usar)
- [ ] Adicionar `storage/ralph/` ao `.gitignore` (os arquivos são gerados)
- [ ] Garantir que `claude` ou `codex` CLI esteja disponível no PATH

## Conceitos-Chave

### Uma story por iteração

O AI implementa **exatamente uma** user story por execução. Isso garante:
- Commits focados e atômicos
- Facilidade de code review
- Rollback granular se algo der errado
- Progress.txt com contexto preciso

### Aprendizado cumulativo

A seção "Codebase Patterns" no `progress.txt` cria memória entre iterações:
- Iteração 1 descobre que o projeto usa `ilike` para busca no PostgreSQL
- Iteração 2 lê esse padrão e já usa `ilike` direto, sem tentativa e erro
- Patterns são gerais e reusáveis, não específicos de uma story

### Sinal de completude

O AI responde com `<promise>COMPLETE</promise>` quando todas as stories passam. O `ralph.sh` detecta esse sinal e encerra o loop com sucesso.

### Arquivo morto automático

Cada nova execução arquiva o run anterior em `storage/ralph/archive/YYYYMMDD_HHMMSS/`, preservando o histórico de PRD e progresso.

### Graceful degradation

Se o AI tool falha em uma iteração, o script continua para a próxima. O PRD não é corrompido porque o AI só marca `passes: true` após commit bem-sucedido.

## Exemplo Real

Feature "Smart Task Filtering" com 3 stories implementadas autonomamente:

```
Iteração 1: US-166 — Kanban due date filter
  -> 102 testes passando, commit feat: US-166
  -> Aprendeu: padrão de filtro no Kanban, match expression

Iteração 2: US-167 — Inbox text search + sortable columns
  -> 65 testes passando, commit feat: US-167
  -> Aprendeu: ilike no PostgreSQL, Flux sortable columns

Iteração 3: US-168 — Features overdue filter
  -> 19 testes passando, commit feat: US-168
  -> Detectou COMPLETE, loop encerrou com sucesso
```

Tempo total: ~15 minutos de execução autônoma para 3 features completas com testes.
