# SoloBoard

Sistema de gestão de projetos de um desenvolvedor solo que também serve, via stakeholder board, como camada de acompanhamento para clientes. Parte do roadmap é espelhada de issues do GitHub.

## Language

Tudo é uma `Activity` numa tabela única com **duas camadas**, separadas pela fonte da verdade (ver [ADR 0004](docs/adr/0004-activities-single-table-two-layers.md)):

- **Roadmap** (mirror): fonte da verdade é o **GitHub**. Tipos `Epic` e `Issue`, com `type` e label derivados do mirror.
- **Pessoal** (local): fonte da verdade é o **SoloBoard**. Tipos `Task` e `Draft`, criados e editados localmente.

O discriminador entre camadas é a coluna `github_issue_number`: tem número → roadmap; não tem → pessoal.

### Roadmap

**PRD**:
Documento de requisitos de uma entrega, escrito a partir de uma conversa e publicado como uma issue no GitHub marcada com `type:prd`. É a origem de um Épico.
_Avoid_: spec, documento, epic, feature

**Épico**:
Unidade de topo do roadmap, espelhada de uma issue `type:prd`. `parent_id` nulo, projeto obrigatório. Atômico (sem filhos) ou decomposto em Fatias. É o que o cliente acompanha na stakeholder board.
_Avoid_: feature, módulo

**Fatia**:
Slice vertical (tracer bullet) de um Épico: um caminho fino e completo por todas as camadas, demoável sozinho. É uma Issue cujo pai é um Épico.
_Avoid_: slice, subtask, fatia horizontal

**Follow-up**:
Issue que nasce de outra issue (tipicamente durante QA de uma Fatia), não diretamente de um PRD — algo fora do AC, descoberto ao implementar. É uma Issue cujo pai é outra Issue, e aninha livremente (follow-up de follow-up é real).
_Avoid_: continuação, desdobramento

**Avulsa**:
Issue do GitHub sem issue-pai, mas com projeto. É uma Issue de `parent_id` nulo.
_Avoid_: loose issue, task órfã

> O label de uma Issue (**Fatia** / **Follow-up** / **Avulsa**) não é um campo: deriva do tipo do pai, subindo a cadeia. Pai `Epic` → Fatia; pai `Issue` → Follow-up; sem pai → Avulsa.

### Pessoal

**Task**:
Trabalho operacional/pessoal do dev ("cobrar manual de marca", "email pro designer"). Vive só no SoloBoard, sem GitHub. Projeto e pai são opcionais.
_Avoid_: card, ticket, subtask

**Ideia** (Draft):
Rascunho local de algo que ainda não virou roadmap. Fica fora de board, na página "Ideias", e só ganha status ao ser promovida. Promover roda `/to-prd` ou `/to-issues`, que cria a issue no GitHub — o mirror então a reflete como Épico ou Issue.
_Avoid_: nota, rascunho solto

### Sincronização

**Mirror**:
Reflexo one-way do GitHub no SoloBoard. O GitHub é a fonte da verdade da camada de roadmap; o SoloBoard reflete o conjunto atual de issues elegíveis e não escreve de volta. A camada pessoal (Task, Draft) vive por cima, sem afetar o mirror.
_Avoid_: integração, two-way sync

**`type:prd`**:
Label que distingue uma issue-PRD (vira Épico) de qualquer outra issue (vira Fatia, Follow-up ou Avulsa, pela cadeia de pais). É o único discriminador.
_Avoid_: label de feature

### Clientes

**Stakeholder**:
Cliente com acesso por email à board pública de um projeto. Lê o roadmap, não edita código.
_Avoid_: usuário, cliente final, viewer

**Stakeholder board**:
Visão pública por projeto onde o stakeholder vê Épicos por status e faz drill-down nas Fatias e Follow-ups, sempre em linguagem natural não-técnica.
_Avoid_: board do cliente, painel, dashboard público
