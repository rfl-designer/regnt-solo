---
name: session
description: Gerencia sessões de AI coding no SoloBoard. Use /session para iniciar, pausar ou finalizar uma sessão de desenvolvimento.
user-invocable: true
---

# Session - Gerenciamento de Sessões de AI Coding

Este skill gerencia o ciclo de vida de sessões de desenvolvimento no SoloBoard.

## Uso

```
/session start <task_id>    # Inicia sessão
/session pause              # Pausa sessão atual
/session finish [resultado] # Finaliza com resultado
/session status             # Mostra sessão atual
```

## Fluxo de Sessão

### Iniciar Sessão

```
# Verificar task
mcp__soloboard__get-task task_id=$0

# Se for session task, mostrar prompt
# Iniciar timer
mcp__soloboard__start-timer task_id=$0 focus=true
```

**Output esperado:**
```
🎯 Sessão Iniciada: Task #X

📝 Prompt da Sessão:
{session_prompt}

⏱️ Timer iniciado em modo foco
📁 Branch sugerida: feat/{slug}

Próximos passos:
1. Criar branch
2. Implementar seguindo o prompt
3. Testar
4. /session finish "Resultado..."
```

### Pausar Sessão

```
# Verificar timer ativo
mcp__soloboard__timer-status

# Parar com notas
mcp__soloboard__stop-timer task_id=X notes="Pausado: $ARGUMENTS"
```

### Finalizar Sessão

```
# Parar timer
mcp__soloboard__stop-timer task_id=X notes="Sessão finalizada"

# Atualizar resultado
mcp__soloboard__update-task task_id=X session_result="$ARGUMENTS" status=done

# Logar commits
# (mostrar comando git log para copiar hashes)
```

**Output esperado:**
```
✅ Sessão Finalizada: Task #X

📊 Resumo:
- Tempo total: Xh Ym
- Commits: N
- Arquivos modificados: M

📝 Resultado registrado:
{session_result}

🔗 Próximo: Criar PR com `gh pr create`
```

### Status da Sessão

```
mcp__soloboard__timer-status
```

**Output esperado:**
```
🔄 Sessão Ativa: Task #X - "Título da task"

⏱️ Tempo decorrido: 1h 23m
🎯 Modo: Foco
📝 Prompt: {primeiros 100 chars...}

Comandos:
- /session pause  - Pausar
- /session finish - Finalizar
```

## Session Tasks vs Regular Tasks

| Aspecto | Session Task | Regular Task |
|---------|--------------|--------------|
| `session_prompt` | Preenchido | Vazio |
| Timer | Focus mode | Normal |
| Commits | Logados | Opcional |
| Resultado | Obrigatório | Opcional |
| Badge | 🤖 Sessão | - |

## Integração com Workflow

1. **Brain** cria session task com `session_prompt`
2. **Implementer** executa seguindo o prompt
3. **Git Specialist** loga commits
4. **Este skill** gerencia o ciclo de vida

## Melhores Práticas

- **Um objetivo por sessão** - Prompt claro e focado
- **Timer em foco** - Evita distrações
- **Resultado descritivo** - O que foi implementado, decisões tomadas
- **Commits granulares** - Facilita rollback se necessário
