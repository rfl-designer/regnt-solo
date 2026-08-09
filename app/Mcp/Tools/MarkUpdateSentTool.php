<?php

namespace App\Mcp\Tools;

use App\Exceptions\UpdateAlreadySentException;
use App\Models\ClientUpdate;
use App\Services\ClientUpdateService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Marca um update como enviado, por MCP (issue #149).
 *
 * Exige o id de um rascunho que existe — e é de propósito que não haja um
 * atalho por cliente. Carimbar "enviado" sem um rascunho na mão registraria
 * no histórico um texto que ninguém escreveu e zeraria o relógio da
 * cadência por engano; a ordem é sempre generate-client-update primeiro,
 * revisar depois, marcar por último.
 */
class MarkUpdateSentTool extends Tool
{
    protected string $name = 'mark-update-sent';

    protected string $description = 'Marks an existing update draft as sent: stamps the send date, closes the window (the next draft will cover from this moment on) and resets the client\'s cadence clock, moving the draft into the client\'s history. Requires the id of a draft that exists — there is deliberately no per-client shortcut, because stamping "sent" without a draft would record something nobody wrote. Get the id from generate-client-update or get-update-queue. Refuses when the id is unknown or when the update was already sent. This tool does not send anything anywhere: delivery happens in the client\'s channel, by the user. Timestamps are ISO 8601 strings with offset.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ClientUpdateService $updates): Response
    {
        $validated = $request->validate([
            'draft_id' => 'required|integer',
        ], [
            'draft_id.required' => 'You must provide the draft_id of an existing update draft. Use generate-client-update or get-update-queue to find it.',
        ]);

        $update = ClientUpdate::find($validated['draft_id']);

        if ($update === null) {
            return Response::error('Update draft not found. Generate one with generate-client-update, or read the open drafts with get-update-queue.');
        }

        try {
            $sent = $updates->markSent($update);
        } catch (UpdateAlreadySentException $e) {
            return Response::error(json_encode([
                'sent' => false,
                'reason' => 'already_sent',
                'draft_id' => $update->id,
                'sent_at' => $update->sent_at?->toIso8601String(),
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $client = $sent->client;

        return Response::text(json_encode([
            'sent' => true,
            'update_id' => $sent->id,
            'client_id' => $client->id,
            'client' => $client->name,
            'sent_at' => $sent->sent_at->toIso8601String(),
            'next_window_starts_at' => $updates->windowStart($client->refresh())->toIso8601String(),
            'message' => "Update de {$client->name} marcado como enviado. O próximo cobre a partir de agora.",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->description('The id of the draft to mark as sent. It must exist and must not have been sent already. Use generate-client-update or get-update-queue to find it.')->required(),
        ];
    }
}
