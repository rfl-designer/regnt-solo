<?php

namespace App\Mcp\Tools;

use App\Exceptions\UpdateDraftHasManualEditsException;
use App\Models\Client;
use App\Services\ClientUpdateService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Gera o rascunho do update semanal de um cliente, por MCP (issue #149).
 *
 * Persiste como a UI persiste, porque é a UI: a tool chama
 * {@see ClientUpdateService::generate()}, o mesmo método que o botão "Gerar
 * update" chama. Não existe um segundo template, nem uma segunda janela —
 * o rascunho que um agente gera é o rascunho que aparece na página, e o
 * humano continua sendo quem decide enviá-lo.
 *
 * A recusa a descartar edição manual também é a mesma: sem `force`, um
 * rascunho mexido à mão não é remontado. O schema descreve; o guard decide.
 */
class GenerateClientUpdateTool extends Tool
{
    protected string $name = 'generate-client-update';

    protected string $description = 'Generates (or regenerates) the weekly update draft for one client and persists it exactly as the Updates page does — same template, same window, same record. The draft is deterministic PT-BR markdown in up to four blocks, empty ones omitted: Entregue (specs that entered Aguardando validação or Feito inside the window, with the state they landed in), Em andamento (approved and not yet delivered, with the hill phrase when the spec has one), Esperando você (what is in the client\'s hands right now, with how long), Próximo (from the pull queue, with the SLE forecast when it is usable). The window runs from the last sent update with no cap; the very first update covers 7 days. Everything is filtered by effective client and by spec level (Épicos and standalone items — never the children of an Épico). Generating never sends: use mark-update-sent for that. Refuses to overwrite a draft that was edited by hand unless force=true. window_start is an ISO 8601 string with offset. There is at most one open draft per client, enforced by the database: generating twice reuses the same record instead of opening a second.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ClientUpdateService $updates): Response
    {
        $validated = $request->validate([
            'client_id' => 'nullable|integer|exists:clients,id',
            'client_slug' => 'nullable|string|exists:clients,slug',
            'force' => 'nullable|boolean',
        ], [
            'client_id.exists' => 'Client not found. Use list-clients to find available client ids.',
            'client_slug.exists' => 'Client not found. Use list-clients to find available client slugs.',
        ]);

        $client = $this->resolveClient($validated);

        if ($client === null) {
            return Response::error('You must provide a client_id or a client_slug. Use list-clients to find them.');
        }

        try {
            $draft = $updates->generate($client, force: (bool) ($validated['force'] ?? false));
        } catch (UpdateDraftHasManualEditsException $e) {
            // Recusa, não falha: o agente precisa saber que existe texto
            // humano em risco e que descartá-lo é uma decisão explícita.
            return Response::error(json_encode([
                'generated' => false,
                'reason' => 'draft_has_manual_edits',
                'draft_id' => $updates->draftFor($client)?->id,
                'message' => $e->getMessage().' Pass force=true to regenerate it from the board and discard the manual text.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return Response::text(json_encode([
            'generated' => true,
            'draft_id' => $draft->id,
            'client_id' => $client->id,
            'client' => $client->name,
            'window_start' => $updates->windowStart($client)->toIso8601String(),
            'blocks' => array_values(array_map(
                fn (array $block): array => ['key' => $block['key'], 'title' => $block['title'], 'items' => count($block['items'])],
                array_filter($updates->blocks($client), fn (array $block): bool => $block['items'] !== []),
            )),
            'content' => $draft->content,
            'sent_at' => null,
            'message' => "Rascunho salvo para {$client->name}. Marcar como enviado é outro ato: mark-update-sent com draft_id {$draft->id}.",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveClient(array $validated): ?Client
    {
        if (! empty($validated['client_id'])) {
            return Client::find($validated['client_id']);
        }

        if (! empty($validated['client_slug'])) {
            return Client::where('slug', $validated['client_slug'])->first();
        }

        return null;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->description('The client to write the update for. Required unless client_slug is given. Use list-clients or get-update-queue to find ids.'),
            'client_slug' => $schema->string()->description('The client slug, as an alternative to client_id.'),
            'force' => $schema->boolean()->description('Regenerate even when the open draft was edited by hand, discarding that text. Defaults to false, which refuses instead.'),
        ];
    }
}
