<?php

use App\Enums\ServiceClass;
use App\Exceptions\FixedDateRequiresDueDateException;
use App\Models\Activity;

test('classifying as fixed_date without a due date is refused on create', function () {
    expect(fn () => Activity::factory()->create([
        'service_class' => ServiceClass::FixedDate,
        'due_date' => null,
    ]))->toThrow(FixedDateRequiresDueDateException::class, 'Classificar como Data fixa exige uma data de vencimento (due date).');
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

test('the fixed_date guard fires regardless of the write origin (mass update)', function () {
    $task = Activity::factory()->create([
        'service_class' => ServiceClass::Standard,
    ]);

    expect(fn () => Activity::query()->where('id', $task->id)->first()->update([
        'service_class' => ServiceClass::FixedDate,
        'due_date' => null,
    ]))->toThrow(FixedDateRequiresDueDateException::class);
});
