<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $projects = Project::all();

        // 4 inbox tasks (no project)
        Task::factory()->inbox()->count(4)->create();

        // 4 todo tasks assigned to projects
        Task::factory()->todo()->count(4)->create([
            'project_id' => fn () => $projects->random()->id,
        ]);

        // 4 doing tasks assigned to projects
        Task::factory()->doing()->count(4)->create([
            'project_id' => fn () => $projects->random()->id,
        ]);

        // 4 done tasks assigned to projects
        Task::factory()->done()->count(4)->create([
            'project_id' => fn () => $projects->random()->id,
        ]);

        // 2 overdue tasks
        Task::factory()->overdue()->count(2)->create([
            'project_id' => fn () => $projects->random()->id,
        ]);

        // 2 unassigned backlog tasks
        Task::factory()->count(2)->create([
            'status' => \App\Enums\TaskStatus::Backlog,
        ]);
    }
}
