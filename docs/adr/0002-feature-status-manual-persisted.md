# Feature.status passa a ser manual e persistido (deixa de ser derivado)

Hoje `Feature::status` é um atributo derivado read-only computado a partir do status das Tasks filhas. Vamos torná-lo uma coluna persistida e editável, porque o status da Feature é o que o cliente vê como colunas na stakeholder board e precisa ser controlado por intenção — o dev arrasta o PRD para `Doing`, ou um agente seta via MCP — não inferido do churn rápido das Tasks.

## Considered Options

- **Manter derivado** — `Doing` só acenderia enquanto houvesse uma Task em `Doing` (flicker), e o status piscaria com o churn das Tasks. Rejeitado: o cliente precisa de um estado estável.
- **Redefinir a derivação por progresso** (`Doing` = começou mas não terminou). Rejeitado: ainda tira o controle das mãos do dev/agente.

## Consequências

- Migration: coluna `status` em `features` (enum `FeatureStatus`, default `Draft`). O getter derivado vira cast de coluna; `progress` (% de Tasks Done) continua derivado, separado do status.
- A stakeholder board ganha drag-and-drop (`wire:sort`) escrevendo `status`; o MCP `update-feature` ganha o parâmetro `status`.
- O sync **nunca** escreve `Feature.status` — é território do dev/agente, 100% manual, inclusive `Done`. Como consequência, `status` (intenção) e `progress` (realidade do GitHub) podem divergir de propósito.
- Task, ao contrário, tem status 100% espelhado do GitHub (overwrite cego) e nunca recebe `Doing`.
