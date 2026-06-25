<?php

use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('shows welcome section for user without any tasks', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSuccessful()
        ->assertSee('Bem-vindo ao SoloBoard');
});

it('hides welcome section when user has tasks', function () {
    $user = User::factory()->create();
    Activity::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSuccessful()
        ->assertDontSee('Bem-vindo ao SoloBoard');
});

it('hides welcome section when user dismissed it', function () {
    $user = User::factory()->create(['hide_welcome_dashboard' => true]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSuccessful()
        ->assertDontSee('Bem-vindo ao SoloBoard');
});

it('dismisses welcome section when user clicks dismiss button', function () {
    $user = User::factory()->create(['hide_welcome_dashboard' => false]);

    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->call('dismissWelcome');

    expect($user->fresh()->hide_welcome_dashboard)->toBeTrue();
});

it('shows dismiss button in welcome section', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSuccessful()
        ->assertSee('Dispensar');
});

it('shows keyboard shortcut hint in welcome section', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSuccessful()
        ->assertSee('Ctrl+N');
});
