<?php

use App\Models\MorningRitual;
use Illuminate\Support\Facades\Schema;

/**
 * O registro do ritual matinal (issue #147): o modelo do plano diário
 * reaproveitado como *evento*, não como lista.
 */
test('the migration drops the daily plan pivot and adds the ritual timestamp', function () {
    expect(Schema::hasTable('daily_plan_activity'))->toBeFalse()
        ->and(Schema::hasTable('daily_plan_task'))->toBeFalse()
        ->and(Schema::hasTable('daily_plans'))->toBeFalse()
        ->and(Schema::hasTable('morning_rituals'))->toBeTrue()
        ->and(Schema::hasColumns('morning_rituals', ['date', 'completed_at', 'notes']))->toBeTrue();
});

test('the migration adds archived_at to activities', function () {
    expect(Schema::hasColumn('activities', 'archived_at'))->toBeTrue();
});

test('getOrCreateForDate returns one record per day', function () {
    $first = MorningRitual::getOrCreateForDate(MorningRitual::businessToday());
    $second = MorningRitual::getOrCreateForDate(MorningRitual::businessToday());

    expect($second->id)->toBe($first->id)
        ->and(MorningRitual::query()->count())->toBe(1);
});

test('completing the ritual stamps the timestamp and keeps the notes', function () {
    // 11:30 UTC = 08:30 em America/Recife, o fuso de negócio (issue #147).
    $this->travelTo('2026-08-07 11:30:00');

    $ritual = MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete('Comecei pelo que estava esperando.');

    expect($ritual->isCompleted())->toBeTrue()
        ->and($ritual->completedAtLabel())->toBe('08:30')
        ->and($ritual->notes)->toBe('Comecei pelo que estava esperando.')
        ->and(MorningRitual::completedToday())->toBeTrue();
});

test('the first completion of the day is the one that counts', function () {
    $this->travelTo('2026-08-07 11:30:00'); // 08:30 local
    $ritual = MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete('primeira');

    $this->travelTo('2026-08-07 20:45:00'); // 17:45 local, mesmo dia
    $ritual->complete('segunda passada');

    expect($ritual->fresh()->completedAtLabel())->toBe('08:30')
        ->and($ritual->fresh()->notes)->toBe('segunda passada');
});

test('blank notes are stored as null rather than an empty string', function () {
    $ritual = MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete('   ');

    expect($ritual->notes)->toBeNull();
});

test('completedToday is false while the day has no concluded ritual', function () {
    expect(MorningRitual::completedToday())->toBeFalse();

    MorningRitual::getOrCreateForDate(MorningRitual::businessToday());

    expect(MorningRitual::completedToday())->toBeFalse();
});

test('completed_at cannot be mass assigned', function () {
    $ritual = MorningRitual::create([
        'date' => today()->toDateString(),
        'completed_at' => '2020-01-01 00:00:00',
    ]);

    expect($ritual->fresh()->completed_at)->toBeNull();
});
