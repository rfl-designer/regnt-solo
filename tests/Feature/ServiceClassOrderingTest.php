<?php

use App\Enums\ServiceClass;
use App\Models\Activity;

test('scopeOrdered ranks all four service classes canonically: emergency, fixed_date, standard, intangible', function () {
    $intangible = Activity::factory()->create(['service_class' => ServiceClass::Intangible]);
    $standard = Activity::factory()->create(['service_class' => ServiceClass::Standard]);
    $fixedDate = Activity::factory()->create(['service_class' => ServiceClass::FixedDate, 'due_date' => now()->addDays(5)]);
    $emergency = Activity::factory()->emergency()->create();

    $ordered = Activity::query()->ordered()->pluck('id')->all();

    expect($ordered)->toBe([$emergency->id, $fixedDate->id, $standard->id, $intangible->id]);
});

test('scopeOrderByServiceClass ranks the four classes without a tie-breaker', function () {
    $intangible = Activity::factory()->create(['service_class' => ServiceClass::Intangible]);
    $standard = Activity::factory()->create(['service_class' => ServiceClass::Standard]);
    $fixedDate = Activity::factory()->create(['service_class' => ServiceClass::FixedDate, 'due_date' => now()->addDays(5)]);
    $emergency = Activity::factory()->emergency()->create();

    $ordered = Activity::query()->orderByServiceClass()->pluck('id')->all();

    expect($ordered)->toBe([$emergency->id, $fixedDate->id, $standard->id, $intangible->id]);
});

test('scopeOrdered ties within the same service class by sort_order', function () {
    $second = Activity::factory()->create(['service_class' => ServiceClass::Standard, 'sort_order' => 2]);
    $first = Activity::factory()->create(['service_class' => ServiceClass::Standard, 'sort_order' => 1]);

    $ordered = Activity::query()->ordered()->pluck('id')->all();

    expect($ordered)->toBe([$first->id, $second->id]);
});
