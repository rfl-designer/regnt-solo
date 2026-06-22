---
name: sync-github
description: Atualiza a board do SoloBoard que o cliente acompanha a partir do que está no GitHub, já reescrevendo tudo em linguagem simples que qualquer pessoa entende sem saber de programação — os planos maiores viram Features e as tarefas viram Tasks, no status certo. Use sempre que alguém quiser deixar a board do cliente em dia com o GitHub, por exemplo "sincroniza o repositório com a board", "atualiza o roadmap do cliente a partir do GitHub", "puxa as novidades do GitHub pro SoloBoard antes da reunião", "o que eu fechei no GitHub precisa aparecer como concluído na board", "tira da board o que cancelei no GitHub" — mesmo que a pessoa não use a palavra "sincronizar". Não use quando ela só quer criar ou organizar coisas direto no GitHub, apenas conferir status sem mexer na board, mover cards na mão, ou mandar algo do SoloBoard de volta para o GitHub.
---

# Sync GitHub → SoloBoard

Espelha as issues de um repositório do GitHub para o roadmap de um projeto do SoloBoard. É um **Mirror one-way**: o GitHub é a fonte da verdade e o SoloBoard nunca escreve de volta. O alvo é a **stakeholder board** — por isso o conteúdo técnico das issues é reescrito em linguagem natural de produto, para o cliente entender sem jargão.

As decisões de fundo estão registradas — leia antes de mexer no comportamento:
- `CONTEXT.md` (raiz) — glossário: PRD, Feature, Task, Slice, Follow-up, Loose issue, Mirror, Stakeholder board, `type:prd`.
- `docs/adr/0001` (GitHub fonte da verdade + mirror one-way), `0002` (Feature.status manual), `0003` (reescrita para clientes).

> **Backend operacional.** Esta skill persiste exclusivamente via MCP tools, e todo o backend já está pronto: Features (#84/#85) e Tasks (#86) com `github_issue_number`, `github_synced_hash`, `feature_id` e upsert idempotente; `Feature.status` manual via `update-feature`; e a reconciliação por deleção (#87) via `delete-task` (cascade dos TimeEntries). O sync roda completo — sem dry-run forçado.

## Invocação

```
/sync-github <project-slug>
```

O `<project-slug>` é o slug do Project no SoloBoard que vai receber o espelho. O repositório do GitHub vem do clone atual (`gh` infere). O mapa repo↔slug fica anotado em `docs/agents/issue-tracker.md` para você não redigitar — consulte e atualize lá.

## Por que cada regra existe

O cliente acompanha a **mesma board** em que você trabalha o roadmap. Isso impõe duas tensões que moldam todo o resto:

1. **O cliente não pode ler jargão técnico** → o sync reescreve title/description em linguagem de produto antes de persistir. O técnico continua existindo, no GitHub e no `session_prompt`.
2. **A board não pode "tremer"** → reescrever a cada sync faria o texto do cliente mudar sozinho e o custo de LLM explodir. Por isso a reescrita (cara, criativa) só roda quando o original muda de fato; o status (regra barata, determinística) atualiza sempre.

Manter essas duas naturezas separadas — caro/sob-demanda vs barato/sempre — é o coração da skill.

## Fluxo

Rode os passos em ordem. **Features primeiro, Tasks depois** — uma Task só consegue achar sua Feature se o PRD pai já foi espelhado nesta mesma rodada.

### 1. Resolver repo e projeto

- Descubra o repo do clone atual: `gh repo view --json nameWithOwner`.
- Resolva o Project alvo pelo slug (MCP `list-projects`). Se o slug não existir, pare e avise — não crie projeto por conta própria.

### 2. Buscar as issues elegíveis

```
gh issue list --state all --limit 500 \
  --json number,title,body,labels,state,url
```

- Inclua `closed` (vira `Done`). Ignore Pull Requests — PRs não são superfície de triagem neste projeto.
- **Elegível** = toda issue que **não** tem o label `wontfix`. Issues `wontfix` não são espelhadas (e, se já existiam, serão removidas na reconciliação — passo 6).

### 3. Classificar

Para cada issue elegível, o **único** discriminador é o label:

- Tem `type:prd` → vira/atualiza uma **Feature**.
- Não tem `type:prd` → vira/atualiza uma **Task** (seja Slice, Follow-up ou Loose issue — todas são Task).

### 4. Decidir o que reescrever (gate por hash)

Para cada issue, calcule o `source_hash` a partir de **title + body + labels ordenados + state**. Compare com o `github_synced_hash` já guardado na entidade correspondente (busque o estado atual via MCP `list-features`/`list-tasks` do projeto, casando por `github_issue_number`):

- **Hash diferente, ou entidade nova** → reescreva o conteúdo (passo 5) e grave o novo hash.
- **Hash igual** → **não** reescreva. Mantenha o texto plain atual. Ainda assim, atualize o `status` da Task (é determinístico e barato — passo 5).

### 5. Reescrever e persistir (via MCP)

**Reescrita (linguagem natural).** Transforme o conteúdo técnico da issue em linguagem de produto que um cliente não-técnico entenda: foco no *o quê* e no *porquê* para o usuário final, sem nomes de arquivo, classes, comandos ou siglas internas. A reescrita **substitui** o conteúdo — o SoloBoard guarda só a versão plain.

**Feature (issue `type:prd`)** — upsert por `github_issue_number` (MCP `create-feature` se nova, `update-feature` se já existe):
- `title` ← título reescrito em linguagem natural.
- `spec` ← corpo do PRD reescrito em linguagem natural.
- `github_issue_number`, `github_synced_hash`, `priority` (default `medium` salvo label de prioridade explícito).
- **Nunca** envie `status`. O status da Feature é manual (você arrasta na features kanban, ou um agente seta) — o sync não toca (ADR-0002).
- Guarde em memória o mapa `issue# → feature_id` desta rodada (necessário no passo seguinte).

**Task (qualquer outra issue)** — upsert por `github_issue_number` (MCP `create-task`/`update-task`):
- `title`/`description` ← reescritos em linguagem natural.
- `github_issue_number`, `github_synced_hash`, `priority`.
- `status` ← mapeado do GitHub (tabela abaixo). **GitHub vence sempre** — sobrescreva a cada rodada. Nunca envie `doing`.
- `feature_id` ← resolvido pela cadeia de Parent (abaixo).

Mapa de status da Task:

| GitHub | → Task status |
|--------|---------------|
| `closed` (qualquer label) | `done` |
| open + `needs-triage` | `inbox` |
| open + `needs-info` | `backlog` |
| open + `ready-for-agent` ou `ready-for-human` | `todo` |
| open + `wontfix` | não espelha (ver passo 2/6) |

Resolução de `feature_id` (subir a cadeia de Parent):
1. Leia a seção `## Parent` do corpo da issue. Se ela referencia `#N`:
2. Se a issue `#N` é um PRD (`type:prd`) → `feature_id` = o `feature_id` dela no mapa desta rodada.
3. Se `#N` é outra Task (ex.: um Follow-up que aponta para uma Slice) → repita o passo 1 para `#N`, subindo até bater num PRD ou esgotar.
4. Se não há `## Parent`, ou a cadeia nunca chega num PRD → `feature_id` = `null` (Loose issue, Task sem Feature).

### 6. Reconciliar (deletar mortos)

Liste as Tasks já espelhadas do projeto (as que têm `github_issue_number`). Para cada uma cujo número **não** está no conjunto elegível desta rodada — porque a issue virou `wontfix` ou foi removida no GitHub — delete via MCP `delete-task` (os TimeEntries fazem cascade). Assim a board do cliente nunca mostra card morto. Não toque em Tasks de issues ainda elegíveis.

> Features não são reconciliadas por deleção — um PRD removido no GitHub é raro e o status da Feature é seu. Se precisar, remova a Feature manualmente.

### 7. Reportar

Resuma o que aconteceu, em linguagem direta: quantas Features e Tasks criadas/atualizadas, quantas reescritas vs puladas pelo hash, quantas Tasks deletadas na reconciliação, e quais issues caíram como Loose (sem Feature) — essas últimas valem destaque, costumam indicar um `## Parent` faltando no GitHub.

## Checkpoint antes de persistir

Numa board que o cliente acompanha, uma sincronização errada é visível para fora. Por isso, **antes da primeira escrita**, apresente o plano (passos 3–6 resolvidos: o que vira Feature, o que vira Task, o que será reescrito, o que será deletado) e confirme com o usuário. Em re-syncs de rotina onde nada estrutural muda, um resumo curto basta — use bom senso sobre quando o plano merece revisão explícita.

## Fora do escopo

- Escrita de volta no GitHub (SoloBoard → GitHub): o mirror é one-way, sempre.
- Sync agendado/automático: esta skill roda sob demanda. Um command Artisan agendado é evolução futura, não está aqui.
- `due_date` a partir do GitHub (não há campo nativo) e arquivamento de Tasks (reconciliação é por deleção).
