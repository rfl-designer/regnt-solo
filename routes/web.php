<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::livewire('dashboard', 'pages::dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::livewire('inbox', 'pages::inbox')
    ->middleware(['auth'])
    ->name('inbox');

Route::livewire('kanban', 'pages::kanban')
    ->middleware(['auth'])
    ->name('kanban');

Route::livewire('daily', 'pages::daily-planner')
    ->middleware(['auth'])
    ->name('daily');

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

require __DIR__.'/settings.php';
