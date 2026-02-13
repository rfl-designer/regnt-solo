# EPIC 5: Timer / Time Tracking

> **Sessão Claude Code:** Tracking de tempo.
> **Estimativa:** ~30min · 2 tasks

---

## Task 5.1 — Componente SFC: Timer + Notes Modal

```yaml
Prompt: |
    Crie 2 SFCs:

    1. resources/views/components/⚡timer.blade.php:
    - Botão start/stop por task (recebe taskId como prop)
    - Start: stopAllRunning() + cria TimeEntry
    - Stop: dispatch 'open-timer-notes' com entryId
    - Display via Alpine.js setInterval (sem polling)
    - Dispatch 'timer-updated' ao start/stop

    2. resources/views/components/⚡timer-notes-modal.blade.php:
    - Mini modal Flux com textarea
    - Bloqueia até decidir (salvar com notas ou pular)
    - Ao confirmar: preenche stopped_at e notes
    - Dispatch 'timer-updated'

Acceptance Criteria:
    - Start cria TimeEntry, stop abre modal de notas
    - Apenas um timer por vez
    - Modal de notas bloqueia até decisão
    - Display atualiza cada segundo (Alpine)
    - Teste Feature: start, stop, notas

Arquivos:
    - resources/views/components/⚡timer.blade.php
    - resources/views/components/⚡timer-notes-modal.blade.php
    - tests/Feature/TimerTest.php

Commit: "feat: Timer SFC with notes modal on stop"
```

---

## Task 5.2 — Timer Global no Header

```yaml
Prompt: |
    Crie o SFC resources/views/components/⚡global-timer.blade.php:

    - Se timer ativo: "⏱ [task.title] HH:MM:SS" + botão stop
    - Tempo via Alpine.js setInterval
    - Click no nome → dispatch 'open-task-modal'
    - Stop → abre timer-notes-modal
    - Se nenhum timer: não renderiza nada
    - Reage a 'timer-updated'

    Integrar no layout app.blade.php dentro do <flux:header>.

Acceptance Criteria:
    - Timer aparece no header quando ativo
    - Tempo atualiza visualmente
    - Stop abre modal de notas
    - Desaparece sem timer
    - Reativo via evento

Arquivos:
    - resources/views/components/⚡global-timer.blade.php
    - resources/views/layouts/app.blade.php

Commit: "feat: global timer in header SFC with notes on stop"
```
