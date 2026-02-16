---
name: workflow
description: Orquestra o workflow completo de desenvolvimento integrado com SoloBoard. Ativa automaticamente quando o usuário menciona "task", "implementar", "feature", "bug", "fix" ou pede para trabalhar em algo.
---

# Workflow de Desenvolvimento SoloBoard

Este skill orquestra o fluxo completo de desenvolvimento, integrando Claude Code com o SoloBoard via MCP.

## Visão Geral do Fluxo

```
[Task do SoloBoard] → Timer → Branch → Implementar → Testar → PR → Stop Timer → Done
```

## Pré-requisito

O usuário sempre fornece a task do SoloBoard. Verificar detalhes:

```
mcp__soloboard__get-task task_id=X
```

## Fases do Workflow

### Fase 1: Iniciar Trabalho

1. **Buscar detalhes da task** - `mcp__soloboard__get-task`
2. **Iniciar timer** - `mcp__soloboard__start-timer task_id=X focus=true`
3. **Criar branch** - `git checkout -b feat/nome-da-feature`

### Fase 2: Implementação

Seguir padrões do CLAUDE.md:

1. **Implementar** - Código seguindo convenções
2. **Commits incrementais** - Conventional commits
3. **Testar** - `php artisan test --compact --filter=X`

Se precisar de ajuda específica, chamar agentes **explicitamente**:
- "usar agente explorer para encontrar X"
- "usar agente reviewer para revisar o código"
- "usar agente tester para criar testes"

### Fase 3: Revisão

Antes de criar PR:

1. **Lint** - `vendor/bin/pint --dirty`
2. **Testes completos** - `php artisan test --compact`
3. **Revisar código** - Verificar padrões

### Fase 4: Finalização

**IMPORTANTE:** O timer só para APÓS criar a PR.

1. **Push** - `git push -u origin branch`
2. **Criar PR** - `gh pr create --title "..." --body "..."`
3. **Log commits** - `mcp__soloboard__log-commits task_id=X commits=[...] pr_url=URL`
4. **Parar timer** - `mcp__soloboard__stop-timer task_id=X notes="PR criada: URL"`
5. **Marcar done** - `mcp__soloboard__update-task task_id=X status=done`

## Agentes Disponíveis

Chamar explicitamente quando necessário:

| Agente | Quando usar | Como chamar |
|--------|-------------|-------------|
| **Explorer** | Encontrar arquivos, entender código | "usar agente explorer para..." |
| **Implementer** | Implementar funcionalidade | "usar agente implementer para..." |
| **Tester** | Criar ou rodar testes | "usar agente tester para..." |
| **Reviewer** | Revisar qualidade do código | "usar agente reviewer para..." |
| **Git Specialist** | Operações git complexas | "usar agente git-specialist para..." |
| **Brain** | Planejar implementação | "usar agente brain para..." |

## Atalhos Rápidos

### Pausar Trabalho Atual
```
mcp__soloboard__stop-timer task_id=X notes="Pausado: motivo"
```

### Retomar Trabalho
```
mcp__soloboard__start-timer task_id=X
```

### Ver Status
```
mcp__soloboard__timer-status
mcp__soloboard__list-tasks status=doing
```

## Session Tasks

Para tasks com `session_prompt`:

1. Ler o prompt da sessão
2. Implementar seguindo as instruções
3. Ao finalizar, registrar resultado:
   ```
   mcp__soloboard__update-task task_id=X session_result="O que foi implementado..."
   ```

## Checklist de Finalização

Antes de marcar como done:

- [ ] Código implementado
- [ ] Testes passando
- [ ] Lint OK (`pint --dirty`)
- [ ] Push feito
- [ ] **PR criada**
- [ ] Commits logados no SoloBoard
- [ ] Timer parado
- [ ] Task marcada como done
