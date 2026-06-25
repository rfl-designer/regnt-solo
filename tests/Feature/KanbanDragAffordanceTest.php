<?php

use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('kanban cards have drag affordance classes', function () {
    Activity::factory()->backlog()->create(['title' => 'Test task']);

    Livewire::test('pages::kanban')
        ->assertSeeHtml('class="kanban-card"')
        ->assertSeeHtml('cursor-grab')
        ->assertSeeHtml('wire:sort:handle');
});

test('kanban drop zones have transition classes', function () {
    Livewire::test('pages::kanban')
        ->assertSeeHtml('class="kanban-dropzone')
        ->assertSeeHtml('transition-colors');
});

test('kanban cards have hover states', function () {
    Activity::factory()->backlog()->create();

    Livewire::test('pages::kanban')
        ->assertSeeHtml('hover:border-zinc-500')
        ->assertSeeHtml('hover:shadow-lg');
});
