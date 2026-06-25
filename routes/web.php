<?php

use App\Support\RealtimeEntitySync;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('/realtime/snapshot', fn () => response()->json(RealtimeEntitySync::snapshot()))
    ->name('realtime.snapshot');

Route::livewire('dashboard', 'pages::dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::livewire('inbox', 'pages::inbox')
    ->middleware(['auth'])
    ->name('inbox');

Route::livewire('tasks', 'pages::tasks')
    ->middleware(['auth'])
    ->name('tasks');

Route::livewire('kanban', 'pages::kanban')
    ->middleware(['auth'])
    ->name('kanban');

Route::livewire('daily', 'pages::daily-planner')
    ->middleware(['auth'])
    ->name('daily');

Route::livewire('weekly', 'pages::weekly-calendar')
    ->middleware(['auth'])
    ->name('weekly');

Route::livewire('projects', 'pages::projects')
    ->middleware(['auth'])
    ->name('projects');

Route::livewire('projects/{slug}', 'pages::project-detail')
    ->middleware(['auth'])
    ->name('project.detail');

Route::livewire('docs/new', 'pages::document-edit')
    ->middleware(['auth'])
    ->name('document.create');

Route::livewire('docs/{slug}/edit', 'pages::document-edit')
    ->middleware(['auth'])
    ->name('document.edit');

Route::livewire('docs/{slug}', 'pages::document-view')
    ->middleware(['auth'])
    ->name('document.view');

Route::livewire('time', 'pages::time-report')
    ->middleware(['auth'])
    ->name('time');

Route::livewire('review', 'pages::weekly-review')
    ->middleware(['auth'])
    ->name('review');

Route::livewire('analytics', 'pages::analytics')
    ->middleware(['auth'])
    ->name('analytics');

Route::livewire('templates', 'pages::templates')
    ->middleware(['auth'])
    ->name('templates');

Route::livewire('prompts', 'pages::prompts')
    ->middleware(['auth'])
    ->name('prompts');

// Public stakeholder view (no auth required)
Route::livewire('projects/shared/{token}', 'pages::project-stakeholder-view')
    ->name('project.stakeholder-view');

require __DIR__.'/settings.php';
