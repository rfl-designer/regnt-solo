<?php

use App\Models\WeeklyReview;
use Carbon\CarbonInterface;

// ─── US-001: Migration, Model e Factory ──────────────────────────────

test('weekly review can be created via factory', function () {
    $review = WeeklyReview::factory()->create();

    expect($review)->toBeInstanceOf(WeeklyReview::class)
        ->and($review->week_start)->toBeInstanceOf(CarbonInterface::class)
        ->and($review->week_end)->toBeInstanceOf(CarbonInterface::class)
        ->and($review->generated_at)->toBeInstanceOf(CarbonInterface::class);
});

test('weekly review factory thisWeek state creates review for current week', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();

    expect($review->week_start->toDateString())->toBe(now()->startOfWeek()->toDateString())
        ->and($review->week_end->toDateString())->toBe(now()->endOfWeek()->toDateString());
});

test('weekly review factory lastWeek state creates review for previous week', function () {
    $review = WeeklyReview::factory()->lastWeek()->create();

    expect($review->week_start->toDateString())->toBe(now()->subWeek()->startOfWeek()->toDateString())
        ->and($review->week_end->toDateString())->toBe(now()->subWeek()->endOfWeek()->toDateString());
});

test('weekly review factory withReflection state sets reflection text', function () {
    $review = WeeklyReview::factory()->withReflection()->create();

    expect($review->reflection)->not->toBeNull()
        ->and($review->reflection)->toBeString();
});

test('week_start unique constraint prevents duplicate reviews for same week', function () {
    WeeklyReview::factory()->thisWeek()->create();

    expect(fn () => WeeklyReview::factory()->thisWeek()->create())
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('scope forWeek finds review for given date', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();
    WeeklyReview::factory()->lastWeek()->create();

    $found = WeeklyReview::query()->forWeek(now())->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($review->id);
});

test('scope forWeek finds review when date is mid-week', function () {
    $wednesday = now()->startOfWeek()->addDays(2);
    $review = WeeklyReview::factory()->thisWeek()->create();

    $found = WeeklyReview::query()->forWeek($wednesday)->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($review->id);
});

test('scope forWeek returns null when no review exists for week', function () {
    WeeklyReview::factory()->lastWeek()->create();

    $found = WeeklyReview::query()->forWeek(now())->first();

    expect($found)->toBeNull();
});

test('getOrCreateForWeek creates review when it does not exist', function () {
    expect(WeeklyReview::count())->toBe(0);

    $review = WeeklyReview::getOrCreateForWeek(now());

    expect(WeeklyReview::count())->toBe(1)
        ->and($review->week_start->toDateString())->toBe(now()->startOfWeek()->toDateString())
        ->and($review->week_end->toDateString())->toBe(now()->endOfWeek()->toDateString())
        ->and($review->generated_at)->not->toBeNull();
});

test('getOrCreateForWeek returns existing review without creating duplicate', function () {
    $existing = WeeklyReview::factory()->thisWeek()->create();

    $review = WeeklyReview::getOrCreateForWeek(now());

    expect(WeeklyReview::count())->toBe(1)
        ->and($review->id)->toBe($existing->id);
});

test('getOrCreateForWeek normalizes mid-week date to monday', function () {
    $thursday = now()->startOfWeek()->addDays(3);

    $review = WeeklyReview::getOrCreateForWeek($thursday);

    expect($review->week_start->toDateString())->toBe(now()->startOfWeek()->toDateString());
});

test('casts are correctly applied to weekly review attributes', function () {
    $review = WeeklyReview::factory()->thisWeek()->create();
    $review->refresh();

    expect($review->week_start)->toBeInstanceOf(CarbonInterface::class)
        ->and($review->week_end)->toBeInstanceOf(CarbonInterface::class)
        ->and($review->generated_at)->toBeInstanceOf(CarbonInterface::class);
});
