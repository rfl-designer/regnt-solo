<?php

namespace App\Models;

use Database\Factories\ClientUpdateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um update semanal de cliente (issue #149).
 *
 * O ciclo de vida inteiro cabe numa coluna: `sent_at` nulo é o rascunho da
 * semana, `sent_at` preenchido é o que o cliente recebeu. Não há status,
 * porque não há um terceiro estado — e é justamente essa coluna que a
 * cadência lê para saber desde quando o próximo update precisa cobrir.
 */
class ClientUpdate extends Model
{
    /** @use HasFactory<ClientUpdateFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'content',
        'generated_content',
        // `sent_at` fica de fora de propósito: marcar como enviado é um ato,
        // não um campo de formulário. Só ClientUpdateService::markSent() o
        // carimba, com now(), para que a data do histórico não possa ser
        // forjada por um payload da UI ou do MCP.
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * O rascunho: o update que ainda não foi para o cliente.
     */
    public function scopeDraft(Builder $query): void
    {
        $query->whereNull('sent_at');
    }

    /**
     * O histórico: o que o cliente de fato recebeu.
     */
    public function scopeSent(Builder $query): void
    {
        $query->whereNotNull('sent_at');
    }

    public function isDraft(): bool
    {
        return $this->sent_at === null;
    }

    /**
     * Se o texto foi mexido à mão depois de gerado.
     *
     * A comparação é contra o texto que o gerador produziu (guardado em
     * `generated_content`), não contra uma geração nova: o quadro se move o
     * tempo todo, e regerar para comparar acusaria edição manual sempre que
     * uma task mudasse de status.
     */
    public function hasManualEdits(): bool
    {
        return trim($this->content) !== trim((string) $this->generated_content);
    }
}
