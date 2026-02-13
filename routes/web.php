<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::livewire('dashboard', 'pages::dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

require __DIR__.'/settings.php';
