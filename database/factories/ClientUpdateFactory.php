<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientUpdate>
 */
class ClientUpdateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = "**Entregue**\n- ".fake()->sentence(4);

        return [
            'client_id' => Client::factory(),
            'content' => $content,
            'generated_content' => $content,
            'sent_at' => null,
        ];
    }

    /**
     * Um update já enviado — o que forma o histórico e fecha a janela do
     * próximo rascunho.
     */
    public function sent(?string $at = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'sent_at' => $at ?? now(),
        ]);
    }

    /**
     * Um rascunho mexido à mão depois de gerado.
     */
    public function edited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'content' => ($attributes['generated_content'] ?? '')."\n\nAbraço!",
        ]);
    }
}
