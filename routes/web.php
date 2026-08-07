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

Route::livewire('ideas', 'pages::ideas')
    ->middleware(['auth'])
    ->name('ideas');

Route::livewire('kanban', 'pages::kanban')
    ->middleware(['auth'])
    ->name('kanban');

// O ritual matinal substitui o Daily Planner (issue #147). A rota antiga
// sobrevive só como redirecionamento, para que links, atalhos e bookmarks
// já existentes cheguem ao lugar certo em vez de darem 404.
Route::livewire('ritual', 'pages::morning-ritual')
    ->middleware(['auth'])
    ->name('ritual');

Route::redirect('daily', '/ritual');

Route::livewire('weekly', 'pages::weekly-calendar')
    ->middleware(['auth'])
    ->name('weekly');

Route::livewire('projects', 'pages::projects')
    ->middleware(['auth'])
    ->name('projects');

Route::livewire('clients', 'pages::clients')
    ->middleware(['auth'])
    ->name('clients');

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

Route::livewire('flow', 'pages::flow')
    ->middleware(['auth'])
    ->name('flow');

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
