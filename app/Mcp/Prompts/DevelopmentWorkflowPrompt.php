<?php

namespace App\Mcp\Prompts;

use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Document;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class DevelopmentWorkflowPrompt extends Prompt
{
    protected string $description = 'Complete development workflow for implementing a task. Guides through: intake, planning, implementation (with skill activation), quality assurance, and delivery. Integrates with all SoloBoard tools.';

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'task_id',
                description: 'The ID of the task to implement.',
                required: true,
            ),
        ];
    }

    /**
     * Handle the prompt request.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        // Get task_id from request, handling different formats
        $taskId = $request->get('task_id');

        // If task_id is not set or empty, return error with helpful message
        if (empty($taskId)) {
            return [
                Response::text("❌ **Erro**: O argumento `task_id` é obrigatório.\n\n**Uso correto:**\n```\ndevelopment-workflow task_id=123\n```\n\nUse `list-tasks status=doing` para ver tasks disponíveis."),
            ];
        }

        // Handle case where Claude Code passes "task_id=123" as the value
        if (is_string($taskId) && str_contains($taskId, '=')) {
            $parts = explode('=', $taskId);
            $taskId = end($parts);
        }

        // Convert to integer
        $taskId = (int) $taskId;

        if ($taskId <= 0) {
            return [
                Response::text("❌ **Erro**: O `task_id` deve ser um número maior que zero.\n\nValor recebido: `{$request->get('task_id')}`"),
            ];
        }

        $task = Task::query()
            ->with(['project', 'commits', 'timeEntries', 'statusChanges', 'recurringTask', 'taskTemplate'])
            ->find($taskId);

        if (! $task) {
            return [
                Response::text("❌ **Erro**: Task com ID `{$taskId}` não encontrada.\n\nUse `list-tasks` para ver tasks disponíveis."),
            ];
        }

        $context = $this->buildTaskContext($task);
        $context .= $this->buildProjectContext($task);
        $context .= $this->buildRelatedDocuments($task);
        $context .= $this->buildRelatedTasks($task);
        $context .= $this->buildTodayContext();

        $workflow = $this->buildWorkflowInstructions($task);

        $systemMessage = <<<'SYSTEM'
You are a development workflow orchestrator for a solo developer using SoloBoard.
Your role is to guide the implementation of the task through a structured workflow:

## WORKFLOW PHASES

### 📥 1. INTAKE E ANÁLISE
- Confirmar que a task foi lida e compreendida
- Iniciar timer imediatamente com `start-timer`
- Atualizar status para "doing" com `update-task`
- Verificar se está na branch correta (main/develop)
- Ler documentos relacionados (PRD/Spec) se existirem
- Se spec não estiver clara, listar perguntas antes de prosseguir

### 📋 2. PLANEJAMENTO
- Explorar código existente: Models, Policies, Migrations, Componentes
- Definir arquitetura e abordagem
- Adicionar ao plano do dia com `add-to-plan`
- Criar branch feature/nome-descritivo

### ⚡ 3. IMPLEMENTAÇÃO (com ativação de skills)
Execute na ordem:
1. Migration + Model (casts, relationships, scopes)
2. Policy / Authorization
3. **Se auth envolvida** → ativar `developing-with-fortify`
4. **Se MCP envolvida** → ativar `mcp-development`
5. Action / Service (lógica de negócio isolada)
6. **Se componente reativo** → ativar `livewire-development`
7. **Se UI components** → ativar `fluxui-development`
8. **Se estilos/layout** → ativar `tailwindcss-development`

### ✅ 4. QUALIDADE
- Validação: Rules Livewire + Constraints DB
- Error Handling: Flash + wire:loading
- **Testes obrigatórios** → ativar `pest-testing`
- Verificar: N+1? Componente pesado? SRP violado?

### 🚀 5. ENTREGA
- Registrar commits com `log-commits`
- Atualizar task com `update-task` (status: done, session_result)
- Parar timer com `stop-timer` e notas do que foi feito
- Push para remote e criar PR
- Verificar métricas com `get-analytics`

## SKILLS DISPONÍVEIS
- `livewire-development` - Componentes reativos Livewire v4
- `fluxui-development` - UI com Flux UI Pro
- `pest-testing` - Testes com Pest 4
- `tailwindcss-development` - Estilos com Tailwind CSS v4
- `mcp-development` - Servidores MCP
- `developing-with-fortify` - Autenticação Laravel Fortify

## REGRAS
1. NUNCA pular a fase de testes
2. SEMPRE iniciar timer antes de começar
3. SEMPRE parar timer com notas ao finalizar
4. SEMPRE registrar commits e PR URL
5. Ativar skills conforme necessidade da feature
6. Commits em inglês, mensagens de UI em português
SYSTEM;

        return [
            Response::text($systemMessage)->asAssistant(),
            Response::text("Vamos implementar esta task seguindo o workflow completo:\n\n".$context."\n\n".$workflow),
        ];
    }

    private function buildTaskContext(Task $task): string
    {
        $context = "## 📋 Task para Implementar\n";
        $context .= "- **ID**: {$task->id}\n";
        $context .= "- **Título**: {$task->title}\n";
        $context .= "- **Status atual**: {$task->status->label()}\n";
        $context .= "- **Prioridade**: {$task->priority->label()}\n";

        if ($task->due_date) {
            $daysUntil = Carbon::today()->diffInDays($task->due_date, false);
            $urgency = $daysUntil < 0 ? '🔴 ATRASADA' : ($daysUntil <= 2 ? '🟡 URGENTE' : '🟢 OK');
            $context .= "- **Due date**: {$task->due_date->toDateString()} ({$urgency})\n";
        }

        if ($task->estimated_minutes) {
            $hours = round($task->estimated_minutes / 60, 1);
            $context .= "- **Estimativa**: {$hours}h\n";
        }

        $workedMinutes = $task->timeEntries->sum('duration_minutes');
        if ($workedMinutes > 0) {
            $workedHours = round($workedMinutes / 60, 1);
            $context .= "- **Tempo trabalhado**: {$workedHours}h\n";
        }

        if ($task->description) {
            $context .= "\n### Descrição\n{$task->description}\n";
        }

        if ($task->session_prompt) {
            $context .= "\n### Session Prompt (User Story)\n{$task->session_prompt}\n";
        }

        if ($task->session_result) {
            $context .= "\n### Session Result (trabalho anterior)\n{$task->session_result}\n";
        }

        if ($task->commits->isNotEmpty()) {
            $context .= "\n### Commits existentes\n";
            foreach ($task->commits->sortByDesc('committed_at')->take(5) as $commit) {
                $context .= "- `{$commit->short_hash}`: {$commit->message}\n";
            }
        }

        if ($task->pr_url) {
            $context .= "\n### Pull Request\n- {$task->pr_url}\n";
        }

        return $context;
    }

    private function buildProjectContext(Task $task): string
    {
        if (! $task->project) {
            return "\n## 📁 Projeto\n- *Sem projeto atribuído*\n";
        }

        $project = $task->project;
        $context = "\n## 📁 Projeto: {$project->emoji} {$project->name}\n";

        if ($project->description) {
            $context .= "- **Descrição**: {$project->description}\n";
        }

        $context .= "- **Status**: {$project->status->label()}\n";
        $context .= "- **Slug**: `{$project->slug}`\n";

        $taskCounts = [
            'doing' => Task::query()->where('project_id', $project->id)->byStatus(TaskStatus::Doing)->count(),
            'todo' => Task::query()->where('project_id', $project->id)->byStatus(TaskStatus::Todo)->count(),
            'backlog' => Task::query()->where('project_id', $project->id)->byStatus(TaskStatus::Backlog)->count(),
        ];

        $context .= "- **Tasks ativas**: {$taskCounts['doing']} doing, {$taskCounts['todo']} todo, {$taskCounts['backlog']} backlog\n";

        return $context;
    }

    private function buildRelatedDocuments(Task $task): string
    {
        if (! $task->project) {
            return '';
        }

        $documents = Document::query()
            ->where('project_id', $task->project_id)
            ->whereIn('type', ['prd', 'spec', 'decision'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        if ($documents->isEmpty()) {
            return '';
        }

        $context = "\n## 📄 Documentos Relacionados\n";
        foreach ($documents as $doc) {
            $pinned = $doc->is_pinned ? '📌 ' : '';
            $context .= "- {$pinned}[{$doc->type->value}] **{$doc->title}** (slug: `{$doc->slug}`)\n";
        }
        $context .= "\n*Use `get-document` para ler o conteúdo completo dos documentos.*\n";

        return $context;
    }

    private function buildRelatedTasks(Task $task): string
    {
        if (! $task->project) {
            return '';
        }

        $relatedTasks = Task::query()
            ->where('project_id', $task->project_id)
            ->where('id', '!=', $task->id)
            ->whereIn('status', [TaskStatus::Doing, TaskStatus::Todo])
            ->orderByRaw("CASE status WHEN 'doing' THEN 1 WHEN 'todo' THEN 2 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END")
            ->limit(5)
            ->get();

        if ($relatedTasks->isEmpty()) {
            return '';
        }

        $context = "\n## 🔗 Tasks Relacionadas (mesmo projeto)\n";
        foreach ($relatedTasks as $related) {
            $sessionBadge = $related->isSessionTask() ? '🤖 ' : '';
            $context .= "- [{$related->status->value}] {$sessionBadge}{$related->title} (ID: {$related->id})\n";
        }

        return $context;
    }

    private function buildTodayContext(): string
    {
        $todayPlan = DailyPlan::query()->whereDate('date', Carbon::today())->first();
        $runningEntry = TimeEntry::query()->whereNull('stopped_at')->with('task')->first();

        $context = "\n## ⏰ Contexto de Hoje\n";
        $context .= '- **Data**: '.Carbon::today()->format('d/m/Y (l)')."\n";

        if ($runningEntry) {
            $elapsed = $runningEntry->started_at->diffForHumans(now(), true);
            $context .= "- **Timer ativo**: {$runningEntry->task->title} ({$elapsed})\n";
            $context .= "  ⚠️ *Timer será parado automaticamente ao iniciar esta task*\n";
        } else {
            $context .= "- **Timer ativo**: Nenhum\n";
        }

        if ($todayPlan) {
            $totalTasks = $todayPlan->tasks()->count();
            $completedTasks = $todayPlan->tasks()->wherePivot('completed_at', '!=', null)->count();
            $context .= "- **Plano do dia**: {$completedTasks}/{$totalTasks} tasks completadas\n";
        }

        $minutesToday = TimeEntry::query()
            ->forDate(Carbon::today())
            ->get()
            ->sum(fn (TimeEntry $e) => $e->duration_minutes);
        $hoursToday = round($minutesToday / 60, 1);
        $context .= "- **Horas trabalhadas hoje**: {$hoursToday}h\n";

        return $context;
    }

    private function buildWorkflowInstructions(Task $task): string
    {
        $instructions = "## 🚀 Próximos Passos\n\n";

        if ($task->status === TaskStatus::Done) {
            $instructions .= "⚠️ **Esta task já está concluída!** Se precisar reabrir:\n";
            $instructions .= "1. Use `update-task` para mudar status para `doing`\n";
            $instructions .= "2. Use `start-timer` para iniciar novo timer\n";

            return $instructions;
        }

        $instructions .= "### 1️⃣ INTAKE (execute agora)\n";
        $instructions .= "```\n";
        $instructions .= "1. start-timer task_id={$task->id}\n";

        if ($task->status !== TaskStatus::Doing) {
            $instructions .= "2. update-task task_id={$task->id} status=doing\n";
        }

        $instructions .= "3. Verificar branch: git checkout main && git pull\n";
        $instructions .= "4. Criar feature branch: git checkout -b feature/{$this->slugify($task->title)}\n";
        $instructions .= "```\n\n";

        $instructions .= "### 2️⃣ PLANEJAMENTO\n";
        $instructions .= "- Explorar Models, Migrations, Policies existentes\n";
        $instructions .= "- Definir arquivos que serão criados/modificados\n";
        $instructions .= "- Identificar dependências e ordem de implementação\n\n";

        $instructions .= "### 3️⃣ SKILLS A ATIVAR (conforme necessidade)\n";
        $instructions .= $this->detectRequiredSkills($task);

        $instructions .= "\n### 4️⃣ ENTREGA (ao finalizar)\n";
        $instructions .= "```\n";
        $instructions .= "1. php artisan test --compact (garantir testes passando)\n";
        $instructions .= "2. vendor/bin/pint --dirty (formatar código)\n";
        $instructions .= "3. git add . && git commit -m \"feat: {$task->title}\"\n";
        $instructions .= "4. log-commits task_id={$task->id} commits=[...] pr_url=...\n";
        $instructions .= "5. update-task task_id={$task->id} status=done session_result=\"...\"\n";
        $instructions .= "6. stop-timer task_id={$task->id} notes=\"...\"\n";
        $instructions .= "```\n";

        return $instructions;
    }

    private function detectRequiredSkills(Task $task): string
    {
        $prompt = strtolower($task->session_prompt ?? '');
        $title = strtolower($task->title);
        $description = strtolower($task->description ?? '');
        $combined = $prompt.' '.$title.' '.$description;

        $skills = [];

        $livewireKeywords = ['livewire', 'wire:', 'componente', 'component', 'reativo', 'reactive', 'real-time'];
        if ($this->containsAny($combined, $livewireKeywords)) {
            $skills[] = '- 🔧 `livewire-development` - Componentes reativos detectados';
        }

        $fluxKeywords = ['flux:', 'modal', 'form', 'input', 'button', 'table', 'chart', 'date-picker', 'ui component'];
        if ($this->containsAny($combined, $fluxKeywords)) {
            $skills[] = '- 🔧 `fluxui-development` - UI components detectados';
        }

        $tailwindKeywords = ['estilo', 'style', 'layout', 'grid', 'flex', 'dark mode', 'responsive', 'css', 'design'];
        if ($this->containsAny($combined, $tailwindKeywords)) {
            $skills[] = '- 🔧 `tailwindcss-development` - Estilos/layout detectados';
        }

        $testKeywords = ['test', 'teste', 'spec', 'tdd', 'coverage', 'pest'];
        if ($this->containsAny($combined, $testKeywords)) {
            $skills[] = '- 🔧 `pest-testing` - Testes mencionados';
        }

        $authKeywords = ['login', 'logout', 'auth', 'password', 'registro', 'register', '2fa', 'verification'];
        if ($this->containsAny($combined, $authKeywords)) {
            $skills[] = '- 🔧 `developing-with-fortify` - Autenticação detectada';
        }

        $mcpKeywords = ['mcp', 'tool', 'resource', 'prompt', 'ai server', 'routes/ai'];
        if ($this->containsAny($combined, $mcpKeywords)) {
            $skills[] = '- 🔧 `mcp-development` - MCP server detectado';
        }

        // pest-testing é sempre obrigatório
        $hasPestTesting = in_array('- 🔧 `pest-testing` - Testes mencionados', $skills, true);
        if (! $hasPestTesting) {
            $skills[] = '- 🔧 `pest-testing` - **Sempre obrigatório** (testes são mandatórios)';
        }

        if (count($skills) === 1) {
            return $skills[0]."\n- Analisar durante implementação e ativar outras skills conforme necessidade\n";
        }

        return implode("\n", array_unique($skills))."\n";
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function slugify(string $text): string
    {
        $text = preg_replace('/[^a-zA-Z0-9\s-]/', '', $text);
        $text = preg_replace('/[\s_]+/', '-', $text);
        $text = strtolower(trim($text, '-'));

        return substr($text, 0, 50);
    }
}
