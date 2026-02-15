<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

class WeeklyReview extends Model
{
    /** @use HasFactory<\Database\Factories\WeeklyReviewFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'week_start',
        'week_end',
        'notes',
        'reflection',
        'generated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Scope to find the review for the week containing the given date.
     */
    public function scopeForWeek(Builder $query, CarbonInterface $date): void
    {
        $query->whereDate('week_start', $date->copy()->startOfWeek());
    }

    /**
     * Get or create a WeeklyReview for the week containing the given date.
     */
    public static function getOrCreateForWeek(CarbonInterface $date): static
    {
        $weekStart = $date->copy()->startOfWeek();
        $weekEnd = $date->copy()->endOfWeek();

        return static::query()->whereDate('week_start', $weekStart)->first()
            ?? static::create([
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'generated_at' => now(),
            ]);
    }

    /**
     * Get the date range boundaries for this week.
     *
     * @return array{Carbon, Carbon}
     */
    public function weekRange(): array
    {
        return [
            $this->week_start->copy()->startOfDay(),
            $this->week_end->copy()->endOfDay(),
        ];
    }

    /**
     * Get tasks completed during this week, with project and time entries eager loaded.
     *
     * @return Collection<int, Task>
     */
    public function completedTasks(): Collection
    {
        return Task::query()
            ->where('status', TaskStatus::Done)
            ->whereBetween('completed_at', $this->weekRange())
            ->with(['project', 'timeEntries'])
            ->orderByDesc('completed_at')
            ->get();
    }

    /**
     * Get the total hours tracked during this week.
     */
    public function totalHours(): float
    {
        return TimeEntry::query()
            ->whereNotNull('stopped_at')
            ->whereBetween('started_at', $this->weekRange())
            ->get()
            ->sum('duration_minutes') / 60;
    }

    /**
     * Get hours grouped by project for this week.
     *
     * @return SupportCollection<int, array{project: Project|null, minutes: float}>
     */
    public function hoursByProject(): SupportCollection
    {
        return TimeEntry::query()
            ->whereNotNull('stopped_at')
            ->whereBetween('started_at', $this->weekRange())
            ->with('task.project')
            ->get()
            ->groupBy(fn (TimeEntry $entry): int => $entry->task?->project_id ?? 0)
            ->map(fn (SupportCollection $entries, int $projectId): array => [
                'project' => $entries->first()->task?->project,
                'minutes' => $entries->sum('duration_minutes'),
            ])
            ->sortByDesc('minutes')
            ->values();
    }

    /**
     * Get active tasks that had no status change during this week.
     *
     * @return Collection<int, Task>
     */
    public function staleTasks(): Collection
    {
        $range = $this->weekRange();

        return Task::query()
            ->active()
            ->where('status', '!=', TaskStatus::Inbox)
            ->whereDoesntHave('statusChanges', fn (Builder $query) => $query->whereBetween('changed_at', $range))
            ->with('project')
            ->get();
    }

    /**
     * Get average time (in minutes) per status for tasks completed this week.
     *
     * @return array<string, float>
     */
    public function statusTimeAverages(): array
    {
        $doneTasks = Task::query()
            ->where('status', TaskStatus::Done)
            ->whereBetween('completed_at', $this->weekRange())
            ->with('statusChanges')
            ->get();

        if ($doneTasks->isEmpty()) {
            return [];
        }

        $totals = [];
        $counts = [];

        foreach ($doneTasks as $task) {
            foreach ($task->time_in_status as $status => $minutes) {
                if ($minutes > 0) {
                    $totals[$status] = ($totals[$status] ?? 0) + $minutes;
                    $counts[$status] = ($counts[$status] ?? 0) + 1;
                }
            }
        }

        $averages = [];
        foreach ($totals as $status => $total) {
            $averages[$status] = round($total / $counts[$status], 2);
        }

        return $averages;
    }

    /**
     * Get the count of tasks created vs completed during this week.
     *
     * @return array{created: int, completed: int}
     */
    public function tasksCreatedVsCompleted(): array
    {
        $range = $this->weekRange();

        return [
            'created' => Task::query()
                ->whereBetween('created_at', $range)
                ->count(),
            'completed' => Task::query()
                ->where('status', TaskStatus::Done)
                ->whereBetween('completed_at', $range)
                ->count(),
        ];
    }
}
