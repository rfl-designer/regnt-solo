<?php

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('schedulable scope', function () {
    test('includes issues, personal tasks and atomic epics', function () {
        $issue = Activity::factory()->issue()->create();
        $task = Activity::factory()->task()->create();
        $atomicEpic = Activity::factory()->epic()->create();

        $ids = Activity::query()->schedulable()->pluck('id');

        expect($ids)->toContain($issue->id)
            ->toContain($task->id)
            ->toContain($atomicEpic->id);
    });

    test('excludes epic containers (epics with children)', function () {
        $container = Activity::factory()->epic()->create();
        Activity::factory()->forParent($container)->issue()->create();

        $ids = Activity::query()->schedulable()->pluck('id');

        expect($ids)->not->toContain($container->id);
    });

    test('excludes drafts', function () {
        $draft = Activity::factory()->draft()->create();

        $ids = Activity::query()->schedulable()->pluck('id');

        expect($ids)->not->toContain($draft->id);
    });
});
