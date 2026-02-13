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

require __DIR__.'/settings.php';
