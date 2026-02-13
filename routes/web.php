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

Route::livewire('time', 'pages::time-report')
    ->middleware(['auth'])
    ->name('time');

require __DIR__.'/settings.php';
