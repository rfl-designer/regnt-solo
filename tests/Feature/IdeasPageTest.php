<?php

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('page renders successfully', function () {
    Livewire::test('pages::ideas')
        ->assertSuccessful()
        ->assertSee('Ideias');
});

test('lists only drafts, excluding epics, issues and tasks', function () {
    Activity::factory()->draft()->create(['title' => 'Loose Draft']);
    Activity::factory()->epic()->create(['title' => 'Roadmap Epic']);
    Activity::factory()->issue()->create(['title' => 'Roadmap Issue']);
    Activity::factory()->task()->create(['title' => 'Personal Task']);

    Livewire::test('pages::ideas')
        ->assertSee('Loose Draft')
        ->assertDontSee('Roadmap Epic')
        ->assertDontSee('Roadmap Issue')
        ->assertDontSee('Personal Task');
});

test('creates a draft with title and note', function () {
    Livewire::test('pages::ideas')
        ->call('newDraft')
        ->set('title', 'Calendar view idea')
        ->set('note', 'A monthly calendar for tasks')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $draft = Activity::query()->drafts()->first();

    expect($draft)->not->toBeNull();
    expect($draft->title)->toBe('Calendar view idea');
    expect($draft->description)->toBe('A monthly calendar for tasks');
    expect($draft->type)->toBe(ActivityType::Draft);
    expect($draft->status)->toBeNull();
    expect($draft->github_issue_number)->toBeNull();
});

test('create requires a title', function () {
    Livewire::test('pages::ideas')
        ->call('newDraft')
        ->set('title', '')
        ->call('saveDraft')
        ->assertHasErrors(['title' => 'required']);

    expect(Activity::query()->drafts()->count())->toBe(0);
});

test('edits an existing draft', function () {
    $draft = Activity::factory()->draft()->create(['title' => 'Old title', 'description' => 'old note']);

    Livewire::test('pages::ideas')
        ->call('editDraft', $draft->id)
        ->assertSet('title', 'Old title')
        ->assertSet('note', 'old note')
        ->set('title', 'New title')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $draft->refresh();
    expect($draft->title)->toBe('New title');
});

test('edit refuses a non-draft activity', function () {
    $epic = Activity::factory()->epic()->create();

    expect(fn () => Livewire::test('pages::ideas')->call('editDraft', $epic->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('deletes a draft with confirmation', function () {
    $draft = Activity::factory()->draft()->create(['title' => 'Disposable Idea']);

    Livewire::test('pages::ideas')
        ->call('confirmDelete', $draft->id)
        ->assertSet('deletingDraftId', $draft->id)
        ->call('deleteDraft');

    $this->assertDatabaseMissing('activities', ['id' => $draft->id]);
});

test('delete refuses a non-draft activity', function () {
    $task = Activity::factory()->task()->create();

    expect(fn () => Livewire::test('pages::ideas')->call('confirmDelete', $task->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->assertDatabaseHas('activities', ['id' => $task->id]);
});

test('searches drafts by title', function () {
    Activity::factory()->draft()->create(['title' => 'Calendar view']);
    Activity::factory()->draft()->create(['title' => 'Dark theme toggle']);

    Livewire::test('pages::ideas')
        ->set('search', 'calendar')
        ->assertSee('Calendar view')
        ->assertDontSee('Dark theme toggle');
});
