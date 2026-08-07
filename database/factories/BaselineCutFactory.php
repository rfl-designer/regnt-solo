<?php

namespace Database\Factories;

use App\Models\BaselineCut;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BaselineCut>
 */
class BaselineCutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reason' => 'Mudança no jeito de trabalhar',
            'cut_at' => now(),
        ];
    }
}
