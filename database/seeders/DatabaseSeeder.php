<?php

namespace Database\Seeders;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\DailyPlan;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatusChange;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Solo Admin',
            'email' => config('solo.user_email', 'admin@soloboard.local'),
            'password' => Hash::make(config('solo.user_password', 'password')),
        ]);

        $projects = $this->createProjects();
        $tasks = $this->createTasks($projects);
        $this->createStatusChangeHistory($tasks);
        $this->createTimeEntries($tasks);
        $this->createDailyPlan($tasks);
        $this->createRunningTimer($tasks);
        $this->createTaskCommits($tasks);
        $this->createWeeklyReviews();
    }

    /**
     * Create the 6 realistic projects.
     *
     * @return array<string, Project>
     */
    private function createProjects(): array
    {
        /** @var array<string, array{name: string, slug: string, emoji: string, color: string, status: ProjectStatus, priority: ProjectPriority, description: string}> $definitions */
        $definitions = [
            'api' => [
                'name' => 'API Gateway',
                'slug' => 'api-gateway',
                'emoji' => '🔧',
                'color' => '#3B82F6',
                'status' => ProjectStatus::Active,
                'priority' => ProjectPriority::High,
                'description' => 'Gateway centralizado para microserviços',
            ],
            'landing' => [
                'name' => 'Landing Page v2',
                'slug' => 'landing-page-v2',
                'emoji' => '🚀',
                'color' => '#8B5CF6',
                'status' => ProjectStatus::Active,
                'priority' => ProjectPriority::High,
                'description' => 'Redesign da landing page principal',
            ],
            'mobile' => [
                'name' => 'Mobile App',
                'slug' => 'mobile-app',
                'emoji' => '📱',
                'color' => '#10B981',
                'status' => ProjectStatus::Active,
                'priority' => ProjectPriority::Medium,
                'description' => 'Aplicativo mobile React Native',
            ],
            'admin' => [
                'name' => 'Admin Dashboard',
                'slug' => 'admin-dashboard',
                'emoji' => '📊',
                'color' => '#F59E0B',
                'status' => ProjectStatus::Active,
                'priority' => ProjectPriority::Medium,
                'description' => 'Painel administrativo interno',
            ],
            'design' => [
                'name' => 'Design System',
                'slug' => 'design-system',
                'emoji' => '🎨',
                'color' => '#EC4899',
                'status' => ProjectStatus::Paused,
                'priority' => ProjectPriority::Low,
                'description' => 'Biblioteca de componentes compartilhados',
            ],
            'blog' => [
                'name' => 'Blog Pessoal',
                'slug' => 'blog-pessoal',
                'emoji' => '📝',
                'color' => '#6366F1',
                'status' => ProjectStatus::Active,
                'priority' => ProjectPriority::Low,
                'description' => 'Blog pessoal com artigos técnicos',
            ],
        ];

        $projects = [];

        foreach ($definitions as $key => $data) {
            $projects[$key] = Project::create($data);
        }

        return $projects;
    }

    /**
     * Create 35 realistic tasks distributed across projects and statuses.
     *
     * Tasks are created without events to avoid observer creating status changes,
     * since we generate a realistic retroactive history separately.
     *
     * @param  array<string, Project>  $projects
     * @return \Illuminate\Support\Collection<int, Task>
     */
    private function createTasks(array $projects): \Illuminate\Support\Collection
    {
        /** @var array<int, array{title: string, project: string|null, status: TaskStatus, priority: TaskPriority, estimated_minutes: int|null, due_date: string|null, completed_at: Carbon|null}> $taskDefinitions */
        $taskDefinitions = [
            // API Gateway tasks
            ['title' => 'Configurar rate limiting por endpoint', 'project' => 'api', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'estimated_minutes' => 120, 'due_date' => null, 'completed_at' => now()->subDays(3)],
            ['title' => 'Implementar circuit breaker pattern', 'project' => 'api', 'status' => TaskStatus::Doing, 'priority' => TaskPriority::Urgent, 'estimated_minutes' => 180, 'due_date' => now()->addDays(2)->toDateString(), 'completed_at' => null],
            ['title' => 'Adicionar health check endpoints', 'project' => 'api', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'estimated_minutes' => 60, 'due_date' => now()->addDays(5)->toDateString(), 'completed_at' => null],
            ['title' => 'Documentar API com OpenAPI 3.0', 'project' => 'api', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 240, 'due_date' => null, 'completed_at' => null],
            ['title' => 'Configurar cache de respostas', 'project' => 'api', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 90, 'due_date' => now()->addDays(7)->toDateString(), 'completed_at' => null],
            ['title' => 'Implementar autenticação JWT', 'project' => 'api', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Urgent, 'estimated_minutes' => 150, 'due_date' => null, 'completed_at' => now()->subDays(5)],

            // Landing Page v2 tasks
            ['title' => 'Criar hero section com animações', 'project' => 'landing', 'status' => TaskStatus::Doing, 'priority' => TaskPriority::High, 'estimated_minutes' => 120, 'due_date' => now()->addDay()->toDateString(), 'completed_at' => null],
            ['title' => 'Implementar seção de depoimentos', 'project' => 'landing', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 90, 'due_date' => now()->addDays(4)->toDateString(), 'completed_at' => null],
            ['title' => 'Otimizar imagens para web', 'project' => 'landing', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::Low, 'estimated_minutes' => 45, 'due_date' => null, 'completed_at' => null],
            ['title' => 'Configurar analytics e tracking', 'project' => 'landing', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 60, 'due_date' => null, 'completed_at' => now()->subDays(2)],
            ['title' => 'Criar formulário de contato', 'project' => 'landing', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'estimated_minutes' => 75, 'due_date' => now()->addDays(3)->toDateString(), 'completed_at' => null],

            // Mobile App tasks
            ['title' => 'Setup projeto React Native', 'project' => 'mobile', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'estimated_minutes' => 180, 'due_date' => null, 'completed_at' => now()->subDays(7)],
            ['title' => 'Implementar tela de login', 'project' => 'mobile', 'status' => TaskStatus::Doing, 'priority' => TaskPriority::High, 'estimated_minutes' => 120, 'due_date' => now()->addDays(2)->toDateString(), 'completed_at' => null],
            ['title' => 'Criar navegação bottom tabs', 'project' => 'mobile', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 90, 'due_date' => now()->addDays(6)->toDateString(), 'completed_at' => null],
            ['title' => 'Integrar push notifications', 'project' => 'mobile', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::Low, 'estimated_minutes' => 150, 'due_date' => null, 'completed_at' => null],
            ['title' => 'Configurar CI/CD para builds', 'project' => 'mobile', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 120, 'due_date' => null, 'completed_at' => null],

            // Admin Dashboard tasks
            ['title' => 'Criar dashboard com gráficos', 'project' => 'admin', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High, 'estimated_minutes' => 180, 'due_date' => now()->addDays(5)->toDateString(), 'completed_at' => null],
            ['title' => 'Implementar CRUD de usuários', 'project' => 'admin', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'estimated_minutes' => 120, 'due_date' => null, 'completed_at' => now()->subDays(4)],
            ['title' => 'Adicionar filtros avançados na listagem', 'project' => 'admin', 'status' => TaskStatus::Doing, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 90, 'due_date' => now()->addDays(3)->toDateString(), 'completed_at' => null],
            ['title' => 'Exportar relatórios em PDF', 'project' => 'admin', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::Low, 'estimated_minutes' => 150, 'due_date' => null, 'completed_at' => null],

            // Design System tasks
            ['title' => 'Definir tokens de design (cores, tipografia)', 'project' => 'design', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'estimated_minutes' => 120, 'due_date' => null, 'completed_at' => now()->subDays(10)],
            ['title' => 'Criar componente Button com variantes', 'project' => 'design', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 90, 'due_date' => null, 'completed_at' => now()->subDays(8)],
            ['title' => 'Documentar componentes no Storybook', 'project' => 'design', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::Low, 'estimated_minutes' => 180, 'due_date' => null, 'completed_at' => null],

            // Blog Pessoal tasks
            ['title' => 'Escrever artigo sobre Laravel 12', 'project' => 'blog', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 120, 'due_date' => now()->addDays(7)->toDateString(), 'completed_at' => null],
            ['title' => 'Configurar SEO e meta tags', 'project' => 'blog', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 45, 'due_date' => null, 'completed_at' => now()->subDays(6)],
            ['title' => 'Implementar sistema de comentários', 'project' => 'blog', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::Low, 'estimated_minutes' => 150, 'due_date' => null, 'completed_at' => null],

            // Inbox tasks (no project)
            ['title' => 'Revisar PR do colega', 'project' => null, 'status' => TaskStatus::Inbox, 'priority' => TaskPriority::High, 'estimated_minutes' => 30, 'due_date' => now()->toDateString(), 'completed_at' => null],
            ['title' => 'Atualizar dependências do projeto', 'project' => null, 'status' => TaskStatus::Inbox, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 45, 'due_date' => null, 'completed_at' => null],
            ['title' => 'Pesquisar sobre WebSockets', 'project' => null, 'status' => TaskStatus::Inbox, 'priority' => TaskPriority::Low, 'estimated_minutes' => null, 'due_date' => null, 'completed_at' => null],
            ['title' => 'Responder email do cliente', 'project' => null, 'status' => TaskStatus::Inbox, 'priority' => TaskPriority::Urgent, 'estimated_minutes' => 15, 'due_date' => now()->toDateString(), 'completed_at' => null],
            ['title' => 'Agendar reunião de sprint planning', 'project' => null, 'status' => TaskStatus::Inbox, 'priority' => TaskPriority::Medium, 'estimated_minutes' => null, 'due_date' => now()->addDays(2)->toDateString(), 'completed_at' => null],

            // Session tasks (AI-assisted)
            ['title' => 'Implementar módulo de notificações push', 'project' => 'mobile', 'status' => TaskStatus::Doing, 'priority' => TaskPriority::High, 'estimated_minutes' => 180, 'due_date' => now()->addDays(3)->toDateString(), 'completed_at' => null, 'session_prompt' => 'Implementar sistema de notificações push para o app mobile. Deve suportar notificações em foreground e background, com deep linking para a tela correta. Usar Firebase Cloud Messaging.', 'session_result' => null],
            ['title' => 'Refatorar serviço de autenticação', 'project' => 'api', 'status' => TaskStatus::Done, 'priority' => TaskPriority::High, 'estimated_minutes' => 120, 'due_date' => null, 'completed_at' => now()->subDays(1), 'session_prompt' => 'Refatorar o serviço de autenticação para usar o padrão Strategy, separando JWT e OAuth2 em classes distintas. Manter compatibilidade com a API existente.', 'session_result' => 'Serviço refatorado com sucesso. Criados AuthStrategy interface, JwtStrategy e OAuth2Strategy. 12 testes atualizados, todos passando. PR #87 aberto.'],
            ['title' => 'Criar pipeline de deploy automático', 'project' => 'admin', 'status' => TaskStatus::Doing, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 150, 'due_date' => now()->addDays(5)->toDateString(), 'completed_at' => null, 'session_prompt' => 'Configurar GitHub Actions para deploy automático do Admin Dashboard em staging ao fazer merge na branch develop. Incluir steps de lint, testes e build.', 'session_result' => null],

            // Extra tasks with overdue dates
            ['title' => 'Corrigir bug no upload de arquivos', 'project' => 'api', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Urgent, 'estimated_minutes' => 60, 'due_date' => now()->subDays(2)->toDateString(), 'completed_at' => null],
            ['title' => 'Refatorar módulo de pagamentos', 'project' => 'admin', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::High, 'estimated_minutes' => 240, 'due_date' => now()->subDay()->toDateString(), 'completed_at' => null],
            ['title' => 'Melhorar performance da listagem', 'project' => 'mobile', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 90, 'due_date' => now()->addDays(4)->toDateString(), 'completed_at' => null],
            ['title' => 'Configurar testes E2E com Cypress', 'project' => 'landing', 'status' => TaskStatus::Backlog, 'priority' => TaskPriority::Medium, 'estimated_minutes' => 120, 'due_date' => null, 'completed_at' => null],
        ];

        return Task::withoutEvents(function () use ($taskDefinitions, $projects) {
            $allTasks = collect();

            foreach ($taskDefinitions as $index => $def) {
                $task = Task::create([
                    'title' => $def['title'],
                    'project_id' => $def['project'] !== null ? $projects[$def['project']]->id : null,
                    'status' => $def['status'],
                    'priority' => $def['priority'],
                    'estimated_minutes' => $def['estimated_minutes'],
                    'due_date' => $def['due_date'],
                    'completed_at' => $def['completed_at'],
                    'sort_order' => $index,
                    'session_prompt' => $def['session_prompt'] ?? null,
                    'session_result' => $def['session_result'] ?? null,
                ]);

                $allTasks->push($task);
            }

            return $allTasks;
        });
    }

    /**
     * Create retroactive status change history for all tasks.
     *
     * Generates a realistic timeline of status transitions based on each task's current status.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     */
    private function createStatusChangeHistory(\Illuminate\Support\Collection $tasks): void
    {
        /** @var array<string, list<TaskStatus>> $statusPaths */
        $statusPaths = [
            TaskStatus::Inbox->value => [TaskStatus::Inbox],
            TaskStatus::Backlog->value => [TaskStatus::Inbox, TaskStatus::Backlog],
            TaskStatus::Todo->value => [TaskStatus::Inbox, TaskStatus::Backlog, TaskStatus::Todo],
            TaskStatus::Doing->value => [TaskStatus::Inbox, TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::Doing],
            TaskStatus::Done->value => [TaskStatus::Inbox, TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::Doing, TaskStatus::Done],
        ];

        foreach ($tasks as $task) {
            $path = $statusPaths[$task->status->value];
            $stepsCount = count($path);

            // Spread changes over the last 30 days, ending near now
            $baseDate = Carbon::now()->subDays(rand(15, 30));

            $previousStatus = null;

            foreach ($path as $stepIndex => $status) {
                // Distribute timestamps evenly with some randomness
                $hoursOffset = $stepIndex * rand(24, 72);
                $changedAt = $baseDate->copy()->addHours($hoursOffset)->addMinutes(rand(0, 59));

                // Ensure last step is not in the future
                if ($changedAt->isFuture()) {
                    $changedAt = Carbon::now()->subMinutes(rand(1, 60 * ($stepsCount - $stepIndex)));
                }

                TaskStatusChange::create([
                    'task_id' => $task->id,
                    'from_status' => $previousStatus,
                    'to_status' => $status,
                    'changed_at' => $changedAt,
                ]);

                $previousStatus = $status;
            }
        }
    }

    /**
     * Create time entries for the last 30 days with focus sessions.
     *
     * Distributes 1-5 hours per day with ~40% of days having focus sessions.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     */
    private function createTimeEntries(\Illuminate\Support\Collection $tasks): void
    {
        $workTasks = $tasks->filter(fn (Task $t) => $t->status !== TaskStatus::Inbox);

        for ($day = 29; $day >= 0; $day--) {
            $date = Carbon::today()->subDays($day);

            // Skip some weekends (but not all, to create interesting patterns)
            if ($date->isWeekend() && rand(0, 3) > 0) {
                continue;
            }

            $sessionsPerDay = rand(2, 5);
            $startHour = rand(8, 10);
            $isFocusDay = rand(1, 100) <= 40; // ~40% of days have focus sessions

            for ($session = 0; $session < $sessionsPerDay; $session++) {
                $task = $workTasks->random();
                $durationMinutes = rand(30, 120);

                $startedAt = $date->copy()->setHour($startHour)->setMinute(rand(0, 30));
                $stoppedAt = $startedAt->copy()->addMinutes($durationMinutes);

                if ($stoppedAt->hour >= 18) {
                    break;
                }

                // First session of focus days is always a focus session (2+ hours)
                $isFocusSession = $isFocusDay && $session === 0;
                if ($isFocusSession) {
                    $durationMinutes = rand(120, 180);
                    $stoppedAt = $startedAt->copy()->addMinutes($durationMinutes);
                }

                TimeEntry::create([
                    'task_id' => $task->id,
                    'started_at' => $startedAt,
                    'stopped_at' => $stoppedAt,
                    'notes' => fake()->optional(0.4)->sentence(),
                    'is_focus_session' => $isFocusSession,
                    'focus_rating' => $isFocusSession ? rand(3, 5) : null,
                ]);

                $startHour = $stoppedAt->hour + (rand(0, 1) ? 1 : 0);
            }
        }
    }

    /**
     * Create a daily plan for today with 5 tasks.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     */
    private function createDailyPlan(\Illuminate\Support\Collection $tasks): void
    {
        $plan = DailyPlan::create([
            'date' => Carbon::today()->toDateString(),
            'notes' => 'Foco: finalizar circuit breaker e hero section da landing.',
        ]);

        $planTasks = $tasks
            ->filter(fn (Task $t) => in_array($t->status, [TaskStatus::Doing, TaskStatus::Todo], true))
            ->take(5);

        foreach ($planTasks->values() as $index => $task) {
            $plan->tasks()->attach($task->id, [
                'sort_order' => $index,
                'completed_at' => null,
            ]);
        }
    }

    /**
     * Create a running timer on a "doing" task.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     */
    private function createRunningTimer(\Illuminate\Support\Collection $tasks): void
    {
        $doingTask = $tasks->first(fn (Task $t) => $t->status === TaskStatus::Doing);

        if ($doingTask) {
            TimeEntry::create([
                'task_id' => $doingTask->id,
                'started_at' => now()->subMinutes(rand(15, 45)),
                'stopped_at' => null,
            ]);
        }
    }

    /**
     * Create task commits for done tasks.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     */
    private function createTaskCommits(\Illuminate\Support\Collection $tasks): void
    {
        $doneTasks = $tasks->filter(fn (Task $t) => $t->status === TaskStatus::Done);

        foreach ($doneTasks as $task) {
            $commitCount = rand(1, 4);

            for ($i = 0; $i < $commitCount; $i++) {
                $committedAt = $task->completed_at
                    ? $task->completed_at->copy()->subMinutes(rand(10, 120 * ($commitCount - $i)))
                    : now()->subDays(rand(1, 14));

                TaskCommit::create([
                    'task_id' => $task->id,
                    'hash' => fake()->sha1(),
                    'message' => fake()->randomElement([
                        'feat: implement '.fake()->words(3, true),
                        'fix: resolve '.fake()->words(2, true).' issue',
                        'refactor: improve '.fake()->words(2, true),
                        'test: add tests for '.fake()->words(2, true),
                        'chore: update '.fake()->words(2, true),
                    ]),
                    'files_changed' => rand(1, 15),
                    'insertions' => rand(5, 300),
                    'deletions' => rand(0, 100),
                    'committed_at' => $committedAt,
                ]);
            }
        }
    }

    /**
     * Create weekly reviews for the last 2 weeks with reflections.
     */
    private function createWeeklyReviews(): void
    {
        // Last week's review
        $lastWeekStart = Carbon::now()->subWeek()->startOfWeek();
        WeeklyReview::create([
            'week_start' => $lastWeekStart->toDateString(),
            'week_end' => $lastWeekStart->copy()->endOfWeek()->toDateString(),
            'notes' => 'Semana produtiva. Foco no API Gateway e Landing Page. Circuit breaker quase pronto.',
            'reflection' => 'Consegui manter bom ritmo de deep work. Preciso melhorar a organização do backlog do Mobile App. O Design System ficou parado — considerar retomar na próxima sprint.',
            'generated_at' => $lastWeekStart->copy()->endOfWeek(),
        ]);

        // Two weeks ago review
        $twoWeeksStart = Carbon::now()->subWeeks(2)->startOfWeek();
        WeeklyReview::create([
            'week_start' => $twoWeeksStart->toDateString(),
            'week_end' => $twoWeeksStart->copy()->endOfWeek()->toDateString(),
            'notes' => 'Sprint focada em autenticação e setup do mobile. Boa velocidade de entrega.',
            'reflection' => 'JWT implementado com sucesso. React Native setup demorou mais que o esperado. Preciso estimar melhor tasks de setup/infra. Focus sessions ajudaram muito na concentração.',
            'generated_at' => $twoWeeksStart->copy()->endOfWeek(),
        ]);
    }
}
