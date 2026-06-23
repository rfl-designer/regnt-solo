# O sync reescreve o conteúdo técnico em linguagem natural para a stakeholder board

O conteúdo das issues do GitHub (PRD, slices, follow-ups) é técnico, mas a stakeholder board é lida por clientes não-técnicos. Por isso a skill de sync, ao espelhar, reescreve title e description de técnico → linguagem de produto, em vez de copiar verbatim.

## Considered Options

- **Campo separado pro cliente** (`client_title`/`client_summary`) mantendo o técnico no SoloBoard. Rejeitado: o técnico já vive no GitHub (fonte da verdade) e no `session_prompt`; um terceiro lugar é redundante.

## Consequências

- A reescrita **substitui** o conteúdo: `Feature.title/spec` e `Task.title/description` guardam só a versão plain. Para o detalhe técnico, clica-se para o GitHub via `github_issue_number`, ou lê-se o `session_prompt`.
- A reescrita é por LLM (não-determinística), então só roda quando o original muda: uma coluna `github_synced_hash` guarda o digest do source; texto plain estável entre runs sem mudança.
- O `status` (regra determinística, sem LLM) atualiza toda rodada; a reescrita (cara) só no diff.
- Toda a orquestração vive na skill (Claude-no-loop), sem command/service/teste no núcleo — escolha deliberada de manter leve para um fluxo solo.
