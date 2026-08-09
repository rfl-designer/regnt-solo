# Shaping promove a Ideia no lugar; a aposta nasce no SoloBoard

Uma Ideia vira Épico mudando de tipo na própria linha — mesmo `id`, mesma Dor (`description`), mesmo Esboço (`spec`). Escolhemos isso em vez de criar um Épico novo a partir do conteúdo da Ideia, e em vez do handoff para o GitHub que a `promote-draft` fazia antes (ADR 0001), porque shaping e aposta são duas etapas do mesmo registro: quem deu forma quer continuar de onde parou, e o pitch lido do Épico tem de ser o pitch lido da Ideia.

## Consequências

- `promote-draft` (MCP) não toca no GitHub e não roda `/to-prd` nem `/to-issues`. Nesta parte, este ADR supersede o ADR 0001; o resto do mirror (Issues espelhadas por `github_issue_number`) continua valendo.
- As colunas novas são só três — `appetite_days`, `rabbit_holes`, `no_gos`. Dor e Esboço **não** ganham coluna própria: dar coluna a elas partiria a spec do Épico em dois campos.
- A régua da promoção (Dor + Apetite + Esboço + projeto) vive em `App\Services\ShapingService`. A página e o MCP chamam o mesmo objeto, então o "não" é literalmente o mesmo texto — não existe porta dos fundos para pôr uma aposta sem forma no quadro.
- O Épico nasce em Backlog com o ciclo de Spec zerado por construção: as quatro datas são derivadas das entradas em Aguardando aprovação/validação/Feito, e nascer em Backlog não produz nenhuma.
- O pitch é renderizado sob demanda e não é guardado em lugar nenhum, então não envelhece. O template é fixo: cinco seções, sempre na mesma ordem, seções vazias aparecem vazias.
- O aside "Seu histórico" compara o apetite com as Specs validadas desde o último corte de baseline (janela aprovada → validada). Com menos de 3, diz que não tem histórico. Nunca bloqueia nada.
