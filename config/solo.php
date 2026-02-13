<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Solo User Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials are used by the DatabaseSeeder to create the single
    | admin user for this solo-user application.
    |
    */

    'user_email' => env('SOLO_USER_EMAIL', 'admin@soloboard.local'),

    'user_password' => env('SOLO_USER_PASSWORD', 'password'),

];
