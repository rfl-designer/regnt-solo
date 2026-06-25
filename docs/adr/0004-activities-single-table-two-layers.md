# Tudo vira `Activity`: uma tabela única com duas camadas (roadmap espelhado + pessoal local)

Colapsamos `Feature` e `Task` num único model `Activity` (tabela `activities`) com um campo `type` (`Epic`, `Issue`, `Task`, `Draft`) e uma árvore via `parent_id`. Escolhemos isso em vez de manter dois models separados (mais limpo no schema, porém dois boards e duas migrações) ou unificar só na camada de view (mesmo visual, schema intacto), porque o objetivo declarado é o SoloBoard deixar de ser só um gestor de projetos e virar o **gerenciador pessoal** do dev — e isso pede um substrato único, extensível, onde roadmap e vida pessoal convivem na mesma estrutura.

A tabela tem **duas camadas**, separadas pela fonte da verdade. Isso preserva a [ADR 0001](0001-github-as-source-of-truth-mirror.md): o roadmap continua sendo um mirror one-way do GitHub; só **adicionamos** uma camada local por cima.

| Camada | Fonte da verdade | Tipos | `type` / hierarquia |
|--------|------------------|-------|---------------------|
| **Roadmap** (mirror) | **GitHub** | `Epic`, `Issue` | **derivados** do GitHub (label `type:prd` + cadeia de pais) |
| **Pessoal** (local) | **SoloBoard** | `Task`, `Draft` | livres — criados, editados e promovidos localmente |

Discriminador entre camadas: a coluna `github_issue_number`. Tem número → roadmap. Não tem → pessoal.

## Taxonomia (`ActivityType`)

Enum interno em inglês; label PT-BR (cliente). Quatro tipos:

| `type` | camada | é | label cliente |
|--------|--------|----|---------------|
| `Epic` | roadmap | issue GitHub com `type:prd`. Topo (`parent_id null`), projeto obrigatório. Atômico (sem filhos) ou decomposto em fatias. | **Épico** |
| `Issue` | roadmap | issue GitHub sem `type:prd`. Label **derivado do pai** (abaixo). | **Fatia** / **Follow-up** / **Avulsa** |
| `Task` | pessoal | trabalho operacional/pessoal ("cobrar manual de marca", "email pro designer"). Projeto e pai opcionais. Sem GitHub. | **Task** |
| `Draft` | pessoal | ideia que ainda não virou roadmap. Fora de board. | **Ideia** |

O label de uma `Issue` **não é um campo** — sai do tipo do pai, exatamente como o `CONTEXT.md` já definia (sobe a cadeia de pais):

| `Issue` cujo pai é… | label |
|---------------------|-------|
| um `Epic` | **Fatia** (slice vertical do épico) |
| outra `Issue` (Fatia ou Follow-up) | **Follow-up** (nasce de algo fora do AC, descoberto ao implementar) |
| nada (`parent_id null`) | **Avulsa** (loose issue: issue GitHub sem pai, com projeto) |

Follow-ups aninham livremente (follow-up de follow-up é real) — isso **reverte** a regra anterior de "sem sub-issue", por design.

## Status (`ActivityStatus`)

Um eixo único, **persistido e manual** (mantém a [ADR 0002](0002-feature-status-manual-persisted.md)): `Inbox` · `Backlog` · `Todo` · `Doing` · `Done`. O case `Draft` do antigo `FeatureStatus` é **aposentado** (Draft virou um `type`, não um status). `Draft` não tem status de board — vive na página "Ideias" e só ganha status ao promover.

Uso típico por tipo: `Epic` nasce em `Backlog`; `Issue`/`Task` podem passar por `Inbox`; nenhum tipo é obrigado a usar todos os valores.

## Hierarquia: dois eixos independentes

- `parent_id` (activity → activity): a árvore. `Epic` é sempre topo; `Issue` pendura num `Epic` (→ Fatia), noutra `Issue` (→ Follow-up) ou em nada (→ Avulsa); `Task` tem pai opcional (`Epic`/`Issue`/nada).
- `project_id` (activity → projeto): agrupamento ortogonal. `Epic` e `Issue` têm projeto obrigatório; `Task` e `Draft`, opcional.

```
Épico            type=Epic, topo, projeto obrigatório, atômico OU decomposto
 ├─ Fatia        type=Issue, pai = Épico
 │   └─ Follow-up   type=Issue, pai = Issue (cadeia livre)
 └─ Task         type=Task, pai opcional
Avulsa           type=Issue, sem pai, com projeto (loose issue do GitHub)
Task pessoal     type=Task, sem projeto
Ideia            type=Draft, fora de board
```

## Telas

A regra-chave dos boards de trabalho é **folha acionável**: itens sem filhos (issues + épicos atômicos). Épico-container aparece só como agrupador no board do projeto.

| Tela | Mostra | Filtro |
|------|--------|--------|
| `/projects/{slug}` | board do projeto: Épicos (+ drill-down em Fatias/Follow-ups) + painel lateral de Tasks do projeto | `project_id` |
| `/kanban` | global, folhas acionáveis | `type=Issue` **+** `type=Epic` sem filhos |
| **Ideias** *(nova)* | drafts | `type=Draft` |
| **Tarefas** *(nova)* | tasks pessoais | `type=Task` |
| daily-planner / weekly-calendar | folhas agendáveis | `type in [Issue, Task]` + épicos atômicos |
| inbox *(existe)* | não triados | `status=Inbox` |
| stakeholder board *(existe)* | público por projeto: Épicos por status, drill em Fatias/Follow-ups, linguagem natural | `project_id` |
| ~~`/work`~~ | **REMOVIDO** — PRDs/Épicos passam a viver em `/projects/{slug}` | — |

## Integração GitHub

Preserva a ADR 0001 inteira. O `type` dos itens de roadmap **não é editável** no SoloBoard — é derivado do mirror (`type:prd` → `Epic`; senão → `Issue`; label pela cadeia de pais). Promover um `Draft` **não** é mutar `type` local: é rodar `/to-prd` ou `/to-issues`, que **cria a issue no GitHub**; o mirror então a reflete como `Epic`/`Issue`. O Draft é o rascunho local de onde a issue nasce (encaixa com `session_prompt`/`session_result` e o fluxo de grilling).

## Agendamento

Reusa `DailyPlan`, `RecurringTask` e `weekly-calendar` como estão — só re-aponta o pivot/FK para `activity_id`. A reformulação desses mecanismos fica **fora do escopo** desta mudança (capítulo futuro).

## Migração de dados

Discriminada por `github_issue_number` — sem heurística ambígua:

- `feature` (todas têm número) → `Activity` `type=Epic`.
- `task` **com** `github_issue_number` → `type=Issue` (roadmap). `parent_id` resolvido pela cadeia de pais que o mirror já mantém (Fatia se pai é Épico, Follow-up se pai é issue, Avulsa se sem pai).
- `task` **sem** `github_issue_number` → `type=Task` (pessoal, local).
- `type=Draft` e Tasks pessoais começam **vazios** (conceitos novos).

Tabelas relacionadas re-apontam FK `task_id`/`feature_id` → `activity_id`: `time_entries`, `task_status_changes`, `task_commits`, pivot `daily_plan_task`, `recurring_tasks`, `task_templates`, `stakeholder_issues`. Colunas de sessão (`session_prompt`, `session_result`, `pr_url`) e de sync (`github_issue_number`, `github_synced_hash`) passam a viver em `activities`.

## Vocabulário (supersede parte do `CONTEXT.md`)

Virada **deliberada** na ubiquitous language voltada ao cliente, que antes evitava "epic":

| Antes (`CONTEXT.md`) | Agora |
|----------------------|-------|
| Feature (representa um PRD) | **Épico** |
| Slice | **Fatia** |
| Follow-up | **Follow-up** (mantém) |
| Loose issue | **Avulsa** |
| — | **Task** (pessoal, local — novo) |
| — | **Ideia** / Draft (local — novo) |

"PRD" deixa de nomear a unidade no SoloBoard e volta a ser só o documento/issue `type:prd` do GitHub que origina um Épico. O `CONTEXT.md` deve ser reescrito com esse glossário ao implementar.

## Consequências

- Um model `Activity` "gordo": métodos e colunas que só fazem sentido por tipo (spec só em Épico, `scheduled`/pessoal só em Task, sessão em Issue). Aceito como custo do substrato único.
- `Feature` e `Task` (models, factories, seeders, testes, MCP tools, rotas) deixam de existir como tais — refactor amplo (`type=Issue` é referenciado em todo lugar).
- `/work` morre; `/projects` vira a porta de entrada do roadmap.
- "Avulsa" (loose issue) sobrevive como `type=Issue` sem pai — não é épico atômico.
- Boards de trabalho passam a filtrar por **folha** (sem filhos), não por tipo puro.

## Decisões de implementação

1. **MCP tools**: nomes semânticos por tipo (`list-epics`, `list-issues`, `list-tasks`, `list-drafts`) sobre a tabela única — a IA prompta melhor com nomes explícitos do que com um `list-activities` genérico filtrado.
2. **Quick-add (`Ctrl+N`)**: cria por padrão `type=Task` com `status=Inbox` (captura pessoal rápida, triada depois).
3. **Tabelas relacionadas** renomeadas para consistência: `task_status_changes`→`activity_status_changes`, `daily_plan_task`→`daily_plan_activity`, `task_commits`→`activity_commits`.
