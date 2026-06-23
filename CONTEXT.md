# SoloBoard

Sistema de gestão de projetos de um desenvolvedor solo que também serve, via stakeholder board, como camada de acompanhamento para clientes. Parte do roadmap é espelhada de issues do GitHub.

## Language

### Roadmap

**PRD**:
Documento de requisitos de uma entrega, escrito a partir de uma conversa e publicado como uma issue no GitHub marcada com `type:prd`. É a origem de uma Feature.
_Avoid_: spec, documento, epic

**Feature**:
Unidade de roadmap que representa um PRD dentro do SoloBoard. Agrupa Tasks e é a unidade que o cliente acompanha na stakeholder board.
_Avoid_: epic, módulo

**Task**:
Unidade de trabalho flat (sem subtasks) espelhada de uma issue do GitHub que não seja um PRD — pode ser uma slice, um follow-up ou uma issue solta.
_Avoid_: card, ticket, subtask

**Slice**:
Fatia vertical (tracer bullet) de um PRD: um caminho fino e completo por todas as camadas, demoável sozinho. Vira uma Task ligada à Feature do PRD pai.
_Avoid_: subtask, fatia horizontal

**Follow-up**:
Task que nasce de outra issue (tipicamente durante QA de uma slice), não diretamente de um PRD. Herda a Feature subindo a cadeia de issues-pai até o PRD.
_Avoid_: continuação, desdobramento

**Loose issue** (issue solta):
Issue do GitHub sem issue-pai que vira uma Task sem Feature.
_Avoid_: task órfã, avulsa

### Sincronização

**Mirror**:
Reflexo one-way do GitHub no SoloBoard. O GitHub é a fonte da verdade; o SoloBoard reflete o conjunto atual de issues elegíveis e não escreve de volta.
_Avoid_: integração, two-way sync

**`type:prd`**:
Label que distingue uma issue-PRD (vira Feature) de qualquer outra issue (vira Task). É o único discriminador.
_Avoid_: label de feature

### Clientes

**Stakeholder**:
Cliente com acesso por email à board pública de um projeto. Lê o roadmap, não edita código.
_Avoid_: usuário, cliente final, viewer

**Stakeholder board**:
Visão pública por projeto onde o stakeholder vê Features por status e faz drill-down nas Tasks, sempre em linguagem natural não-técnica.
_Avoid_: board do cliente, painel, dashboard público
