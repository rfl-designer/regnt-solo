<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Project::factory()->active()->highPriority()->create([
            'name' => 'Website Redesign',
            'slug' => 'website-redesign',
            'emoji' => '🎨',
            'color' => '#6366f1',
        ]);

        Project::factory()->active()->mediumPriority()->create([
            'name' => 'API Integration',
            'slug' => 'api-integration',
            'emoji' => '🔧',
            'color' => '#10b981',
        ]);

        Project::factory()->paused()->highPriority()->create([
            'name' => 'Mobile App',
            'slug' => 'mobile-app',
            'emoji' => '📱',
            'color' => '#f59e0b',
        ]);

        Project::factory()->active()->lowPriority()->create([
            'name' => 'Documentation',
            'slug' => 'documentation',
            'emoji' => '📋',
            'color' => '#3b82f6',
        ]);

        Project::factory()->archived()->mediumPriority()->create([
            'name' => 'Legacy Migration',
            'slug' => 'legacy-migration',
            'emoji' => '📦',
            'color' => '#6b7280',
        ]);
    }
}
