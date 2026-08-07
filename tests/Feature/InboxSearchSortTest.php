<?php

use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('inbox search defaults to empty string', function () {
    Livewire::test('pages::inbox')
        ->assertSet('search', '')
        ->assertSet('sortBy', 'created_at')
        ->assertSet('sortDirection', 'desc');
});

test('inbox filters tasks by search term', function () {
    Activity::factory()->create([
        'title' => 'Fix login bug',
        'status' => 'inbox',
    ]);
    Activity::factory()->create([
        'title' => 'Add dashboard chart',
        'status' => 'inbox',
    ]);

    Livewire::test('pages::inbox')
        ->set('search', 'login')
        ->assertSee('Fix login bug')
        ->assertDontSee('Add dashboard chart');
});

test('inbox search is case insensitive', function () {
    Activity::factory()->create([
        'title' => 'Fix Login Bug',
        'status' => 'inbox',
    ]);

    Livewire::test('pages::inbox')
        ->set('search', 'fix login')
        ->assertSee('Fix Login Bug');
});

test('inbox search shows all tasks when empty', function () {
    Activity::factory()->create([
        'title' => 'Task Alpha',
        'status' => 'inbox',
    ]);
    Activity::factory()->create([
        'title' => 'Task Beta',
        'status' => 'inbox',
    ]);

    Livewire::test('pages::inbox')
        ->set('search', '')
        ->assertSee('Task Alpha')
        ->assertSee('Task Beta');
});

test('inbox sorts tasks by title ascending', function () {
    Activity::factory()->create([
        'title' => 'Zebra task',
        'status' => 'inbox',
    ]);
    Activity::factory()->create([
        'title' => 'Alpha task',
        'status' => 'inbox',
    ]);

    Livewire::test('pages::inbox')
        ->call('sort', 'title')
        ->assertSet('sortBy', 'title')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Alpha task', 'Zebra task']);
});

test('inbox toggles sort direction on same column', function () {
    Livewire::test('pages::inbox')
        ->call('sort', 'title')
        ->assertSet('sortBy', 'title')
        ->assertSet('sortDirection', 'asc')
        ->call('sort', 'title')
        ->assertSet('sortDirection', 'desc');
});

test('inbox resets direction to asc on new column', function () {
    Livewire::test('pages::inbox')
        ->set('sortBy', 'created_at')
        ->set('sortDirection', 'desc')
        ->call('sort', 'title')
        ->assertSet('sortBy', 'title')
        ->assertSet('sortDirection', 'asc');
});

test('inbox sorts tasks by service class', function () {
    Activity::factory()->create([
        'title' => 'Intangible task',
        'status' => 'inbox',
        'service_class' => ServiceClass::Intangible,
    ]);
    Activity::factory()->create([
        'title' => 'Emergency task',
        'status' => 'inbox',
        'service_class' => ServiceClass::Emergency,
    ]);

    Livewire::test('pages::inbox')
        ->call('sort', 'service_class')
        ->assertSet('sortBy', 'service_class')
        ->assertSet('sortDirection', 'asc')
        ->assertSee('Intangible task')
        ->assertSee('Emergency task');
});

test('inbox sorts tasks by created_at', function () {
    Activity::factory()->create([
        'title' => 'Old task',
        'status' => 'inbox',
        'created_at' => now()->subDays(5),
    ]);
    Activity::factory()->create([
        'title' => 'New task',
        'status' => 'inbox',
        'created_at' => now(),
    ]);

    Livewire::test('pages::inbox')
        ->call('sort', 'created_at')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Old task', 'New task']);
});

test('inbox search and sort work together', function () {
    Activity::factory()->create([
        'title' => 'Fix API bug',
        'status' => 'inbox',
    ]);
    Activity::factory()->create([
        'title' => 'Fix UI bug',
        'status' => 'inbox',
    ]);
    Activity::factory()->create([
        'title' => 'Add new feature',
        'status' => 'inbox',
    ]);

    Livewire::test('pages::inbox')
        ->set('search', 'Fix')
        ->call('sort', 'title')
        ->assertSee('Fix API bug')
        ->assertSee('Fix UI bug')
        ->assertDontSee('Add new feature')
        ->assertSeeInOrder(['Fix API bug', 'Fix UI bug']);
});

test('inbox search only affects inbox tasks', function () {
    Activity::factory()->create([
        'title' => 'Inbox task matching',
        'status' => 'inbox',
    ]);
    Activity::factory()->todo()->create([
        'title' => 'Todo task matching',
    ]);

    Livewire::test('pages::inbox')
        ->set('search', 'matching')
        ->assertSee('Inbox task matching')
        ->assertDontSee('Todo task matching');
});

test('inbox search is persisted in url', function () {
    Livewire::test('pages::inbox')
        ->set('search', 'test')
        ->assertSet('search', 'test');
});

test('inbox sort is persisted in url', function () {
    Livewire::test('pages::inbox')
        ->call('sort', 'title')
        ->assertSet('sortBy', 'title')
        ->assertSet('sortDirection', 'asc');
});
