<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Solo Admin',
            'email' => config('solo.user_email', 'admin@soloboard.local'),
            'password' => bcrypt(config('solo.user_password', 'password')),
        ]);
    }
}
