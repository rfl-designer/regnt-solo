# GitHub é a fonte da verdade; SoloBoard é um mirror one-way

Apesar de o SoloBoard ser a ferramenta de PM, o roadmap vive como issues do GitHub (produzidas por `/to-prd` e `/to-issues`) e o SoloBoard apenas as espelha. Escolhemos isso em vez de tornar o SoloBoard a fonte da verdade (skills escreveriam direto via MCP) ou um modelo dual com sync bidirecional, porque o fluxo agentic AFK já é GitHub-native (labels de triage, `gh`) e um mirror one-way evita reconciliação de conflitos.

## Consequências

- Uma skill (`/sync-github <project-slug>`) puxa as issues via `gh` e persiste no SoloBoard; nada escreve de volta no GitHub.
- O GitHub vence sempre em title, description, priority e vínculo. Editar uma entidade no SoloBoard é jogar contra a verdade — muda-se a issue.
- A reconciliação é por deleção: issue marcada `wontfix` ou removida → a Task correspondente é deletada (time entries em cascade). O mirror reflete exatamente as issues elegíveis.
- Chave de mapeamento: coluna `github_issue_number` (unique) em `features` e `tasks`, usada para upsert idempotente e para resolver a issue-pai.
