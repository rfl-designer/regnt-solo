<?php

namespace App\Console\Commands;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Feature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RalphExportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soloboard:ralph
                            {feature_id : The ID of the feature to export}
                            {--max-iterations=10 : Maximum Ralph loop iterations}
                            {--dry-run : Show what would be generated without creating files}
                            {--output-dir=storage/ralph : Output directory for Ralph files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export a Feature as Ralph loop files (prd.json, CLAUDE.md, progress.txt)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $featureId = $this->argument('feature_id');
        $dryRun = $this->option('dry-run');
        $maxIterations = (int) $this->option('max-iterations');
        $outputDir = $this->option('output-dir');

        $feature = Feature::query()
            ->with(['project', 'tasks.project'])
            ->find($featureId);

        if ($feature === null) {
            $this->error("Feature #{$featureId} não encontrada.");

            return self::FAILURE;
        }

        $prd = $this->buildPrd($feature, $maxIterations);
        $claudeMd = $this->buildClaudeMd($feature);

        if ($dryRun) {
            $this->info('[SIMULAÇÃO] Arquivos que seriam gerados:');
            $this->newLine();

            $this->line("📄 {$outputDir}/prd.json");
            $this->line(json_encode($prd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->newLine();

            $this->line("📄 {$outputDir}/CLAUDE.md");
            $this->line($claudeMd);
            $this->newLine();

            $this->line("📄 {$outputDir}/progress.txt");
            $this->line('(arquivo vazio, inicializado no primeiro loop)');

            return self::SUCCESS;
        }

        $absoluteDir = str_starts_with($outputDir, '/') ? $outputDir : base_path($outputDir);
        File::ensureDirectoryExists($absoluteDir);

        File::put(
            "{$absoluteDir}/prd.json",
            json_encode($prd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );

        File::put("{$absoluteDir}/CLAUDE.md", $claudeMd);

        if (! File::exists("{$absoluteDir}/progress.txt")) {
            File::put("{$absoluteDir}/progress.txt", '');
        }

        $this->info("Arquivos Ralph gerados para feature \"{$feature->title}\":");
        $this->line("  ✓ {$outputDir}/prd.json ({$feature->tasks->count()} user stories)");
        $this->line("  ✓ {$outputDir}/CLAUDE.md");
        $this->line("  ✓ {$outputDir}/progress.txt");
        $this->newLine();
        $this->info("Execute: ./scripts/ralph.sh --tool claude {$maxIterations}");

        return self::SUCCESS;
    }

    /**
     * Build the PRD data structure from a Feature.
     *
     * @return array<string, mixed>
     */
    public function buildPrd(Feature $feature, int $maxIterations = 10): array
    {
        $tasks = $feature->tasks->sortBy('sort_order');

        return [
            'project' => $feature->title,
            'branchName' => "ralph/{$feature->slug}",
            'description' => $feature->spec ?? $feature->title,
            'maxIterations' => $maxIterations,
            'userStories' => $tasks->map(fn ($task) => $this->mapTaskToStory($task))->values()->all(),
        ];
    }

    /**
     * Map a Task to a Ralph user story.
     *
     * @return array<string, mixed>
     */
    private function mapTaskToStory($task): array
    {
        $description = $task->session_prompt ?? $task->description ?? $task->title;

        return [
            'id' => "US-{$task->id}",
            'title' => $task->title,
            'description' => $description,
            'acceptanceCriteria' => $this->extractAcceptanceCriteria($description),
            'priority' => $this->mapPriority($task->priority),
            'passes' => $task->status === TaskStatus::Done,
            'metadata' => [
                'soloboard_task_id' => $task->id,
                'soloboard_status' => $task->status->value,
            ],
        ];
    }

    /**
     * Extract acceptance criteria from session_prompt or description.
     *
     * @return list<string>
     */
    private function extractAcceptanceCriteria(string $text): array
    {
        $criteria = [];
        $lines = explode("\n", $text);
        $inCriteria = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^#+\s*(Critérios|Acceptance|Criteria)/i', $trimmed)) {
                $inCriteria = true;

                continue;
            }

            if ($inCriteria && preg_match('/^#+\s/', $trimmed)) {
                break;
            }

            if ($inCriteria && preg_match('/^-\s*\[.\]\s*(.+)/', $trimmed, $matches)) {
                $criteria[] = trim($matches[1]);
            } elseif ($inCriteria && preg_match('/^-\s+(.+)/', $trimmed, $matches)) {
                $criteria[] = trim($matches[1]);
            }
        }

        if (empty($criteria)) {
            $criteria[] = "Implementar: {$text}";
        }

        return $criteria;
    }

    /**
     * Map TaskPriority to Ralph priority number (1=urgent, 4=low).
     */
    private function mapPriority(TaskPriority $priority): int
    {
        return match ($priority) {
            TaskPriority::Urgent => 1,
            TaskPriority::High => 2,
            TaskPriority::Medium => 3,
            TaskPriority::Low => 4,
        };
    }

    /**
     * Build the CLAUDE.md content from the stub template.
     */
    public function buildClaudeMd(Feature $feature): string
    {
        $stubPath = base_path('stubs/ralph-claude.md.stub');

        if (! File::exists($stubPath)) {
            return $this->buildClaudeMdFallback($feature);
        }

        $stub = File::get($stubPath);

        $projectName = $feature->project?->name ?? 'SoloBoard';
        $featureTitle = $feature->title;

        $taskList = $feature->tasks->map(function ($task) {
            $status = $task->status === TaskStatus::Done ? '✅' : '⬜';

            return "  - {$status} US-{$task->id}: {$task->title} (task_id={$task->id})";
        })->implode("\n");

        return str_replace(
            ['{{ $projectName }}', '{{ $featureTitle }}', '{{ $taskList }}'],
            [$projectName, $featureTitle, $taskList],
            $stub
        );
    }

    /**
     * Fallback CLAUDE.md when stub file is missing.
     */
    private function buildClaudeMdFallback(Feature $feature): string
    {
        $projectName = $feature->project?->name ?? 'SoloBoard';

        return <<<MD
        # Ralph Loop — {$feature->title}

        Project: {$projectName}

        Read prd.json for user stories. Implement one story per iteration.

        After each story:
        1. Run tests and linter
        2. Commit with conventional commits
        3. Mark passes: true in prd.json
        4. Append progress to progress.txt

        When all stories pass: <promise>COMPLETE</promise>
        MD;
    }
}
