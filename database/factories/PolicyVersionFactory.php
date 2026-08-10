<?php

namespace Database\Factories;

use App\Enums\PolicyKey;
use App\Models\PolicyVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyVersion>
 */
class PolicyVersionFactory extends Factory
{
    protected $model = PolicyVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->randomElement(PolicyKey::cases()),
            'body' => fake()->paragraph(),
            'note' => null,
        ];
    }

    /**
     * A version of one specific policy.
     */
    public function forKey(PolicyKey $key): static
    {
        return $this->state(fn (): array => ['key' => $key]);
    }

    /**
     * A version carrying the "por que mudou" note.
     */
    public function withNote(string $note): static
    {
        return $this->state(fn (): array => ['note' => $note]);
    }
}
