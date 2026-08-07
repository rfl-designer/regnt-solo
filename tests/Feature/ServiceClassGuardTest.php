<?php

use App\Enums\ServiceClass;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Models\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('classifying as fixed_date without a due date is refused on create', function () {
    expect(fn () => Activity::factory()->create([
        'service_class' => ServiceClass::FixedDate,
        'due_date' => null,
    ]))->toThrow(FixedDateRequiresDueDateException::class, FixedDateRequiresDueDateException::MESSAGE);
});

test('classifying as fixed_date without a due date is refused on update', function () {
    $task = Activity::factory()->create([
        'service_class' => ServiceClass::Standard,
    ]);

    expect(fn () => $task->update(['service_class' => ServiceClass::FixedDate]))
        ->toThrow(FixedDateRequiresDueDateException::class);
});

test('classifying as fixed_date with a due date succeeds', function () {
    $task = Activity::factory()->create([
        'service_class' => ServiceClass::FixedDate,
        'due_date' => now()->addDays(3),
    ]);

    expect($task->service_class)->toBe(ServiceClass::FixedDate)
        ->and($task->due_date)->not->toBeNull();
});

test('the fixed_date guard fires on a plain model save regardless of where it was fetched from', function () {
    $task = Activity::factory()->create([
        'service_class' => ServiceClass::Standard,
    ]);

    expect(fn () => Activity::query()->where('id', $task->id)->first()->update([
        'service_class' => ServiceClass::FixedDate,
        'due_date' => null,
    ]))->toThrow(FixedDateRequiresDueDateException::class);
});

/**
 * These bypass Eloquent's `saving` event entirely — `ActivityObserver`
 * never runs for them — so the invariant can only be enforced by the
 * `chk_activities_fixed_date_requires_due_date` CHECK constraint at the
 * database level. They must fail with a database-level QueryException,
 * not the domain exception, precisely because the observer is skipped.
 */
test('a builder mass update bypasses the observer but is rejected by the database CHECK constraint', function () {
    $task = Activity::factory()->create([
        'service_class' => ServiceClass::Standard,
        'due_date' => null,
    ]);

    expect(fn () => Activity::query()->where('id', $task->id)->update([
        'service_class' => ServiceClass::FixedDate->value,
    ]))->toThrow(QueryException::class);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('an upsert bypasses the observer but is rejected by the database CHECK constraint', function () {
    $task = Activity::factory()->create([
        'service_class' => ServiceClass::Standard,
        'due_date' => null,
    ]);

    expect(fn () => Activity::upsert([
        [
            'id' => $task->id,
            'type' => $task->type->value,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'service_class' => ServiceClass::FixedDate->value,
            'sort_order' => $task->sort_order,
            'due_date' => null,
        ],
    ], ['id']))->toThrow(QueryException::class);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('a raw query builder write bypasses the observer but is rejected by the database CHECK constraint', function () {
    $task = Activity::factory()->create([
        'service_class' => ServiceClass::Standard,
        'due_date' => null,
    ]);

    expect(fn () => DB::table('activities')->where('id', $task->id)->update([
        'service_class' => ServiceClass::FixedDate->value,
    ]))->toThrow(QueryException::class);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('a builder mass update to fixed_date succeeds when due_date is also set', function () {
    $task = Activity::factory()->create([
        'service_class' => ServiceClass::Standard,
        'due_date' => null,
    ]);

    Activity::query()->where('id', $task->id)->update([
        'service_class' => ServiceClass::FixedDate->value,
        'due_date' => now()->addWeek()->toDateString(),
    ]);

    expect($task->fresh()->service_class)->toBe(ServiceClass::FixedDate);
});
