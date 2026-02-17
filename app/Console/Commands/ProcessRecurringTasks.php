<?php

namespace App\Console\Commands;

use App\Models\RecurringTask;
use Illuminate\Console\Command;

class ProcessRecurringTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soloboard:process-recurring
                            {--dry-run : Show what would be created without actually creating tasks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process recurring tasks and create tasks for those that are due';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $recurringTasks = RecurringTask::query()
            ->active()
            ->due()
            ->with('project')
            ->get();

        if ($recurringTasks->isEmpty()) {
            $this->info('Nenhuma task recorrente para processar.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d task(s) recorrente(s)...',
            $dryRun ? 'Simulando processamento de' : 'Processando',
            $recurringTasks->count()
        ));

        $created = 0;

        foreach ($recurringTasks as $recurringTask) {
            $projectName = $recurringTask->project?->name ?? 'Sem projeto';

            if ($dryRun) {
                $this->line(sprintf(
                    '  [SIMULAÇÃO] Criaria: "%s" (%s)',
                    $recurringTask->title,
                    $projectName
                ));
            } else {
                $task = $recurringTask->process();
                $this->line(sprintf(
                    '  ✓ Criada: "%s" (ID: %d, %s)',
                    $task->title,
                    $task->id,
                    $projectName
                ));
            }

            $created++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d task(s).',
            $dryRun ? 'Seriam criadas' : 'Criadas',
            $created
        ));

        return self::SUCCESS;
    }
}
