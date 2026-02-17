<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TaskTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\TaskTemplateFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'default_priority',
        'default_estimated_minutes',
        'icon',
        'color',
        'is_system',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_priority' => TaskPriority::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TaskTemplate $template): void {
            if (empty($template->slug)) {
                $template->slug = Str::slug($template->name);
            }
        });
    }

    /**
     * Get the tasks created from this template.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Scope to only system templates.
     */
    public function scopeSystem(Builder $query): void
    {
        $query->where('is_system', true);
    }

    /**
     * Scope to only custom (user-created) templates.
     */
    public function scopeCustom(Builder $query): void
    {
        $query->where('is_system', false);
    }

    /**
     * Create a task from this template.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createTask(array $overrides = []): Task
    {
        $defaults = [
            'task_template_id' => $this->id,
            'title' => $this->name,
            'description' => $this->description,
            'status' => TaskStatus::Inbox,
            'priority' => $this->default_priority,
            'estimated_minutes' => $this->default_estimated_minutes,
        ];

        return Task::create(array_merge($defaults, $overrides));
    }
}
