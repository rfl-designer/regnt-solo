<?php

namespace App\Mcp\Tools;

use App\Services\ClientUpdateQueueEntry;
use App\Services\ClientUpdateService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A fila de updates, como um agente a lê (issue #149).
 *
 * Renderiza {@see ClientUpdateService::queue()} — a mesma fila que a página
 * Updates mostra e que a badge da sidebar conta. A tool não ordena nada e
 * não calcula urgência: a ordem que a pessoa vê e a que o agente recebe são
 * a mesma por construção.
 */
#[IsReadOnly]
class GetUpdateQueueTool extends Tool
{
    protected string $name = 'get-update-queue';

    protected string $description = 'Returns the weekly client update queue, ordered by urgency: overdue first (most overdue leading), then due today, then on track. Urgency is derived from each active client\'s cadence (weekday + target time) measured against their last sent update — nothing is set by hand. Each entry carries the client (id, slug, name, channel), the urgency, the cadence moment being charged (due_at), when the client last heard from you, whether a draft is already open and whether it has manual edits. `due_count` is the number the sidebar badge shows. Archived clients are out of the queue entirely. Urgency is a day-level question: on the cadence weekday the client reads due_today from midnight to midnight, whatever the target time, and only turns overdue the next day — due_at carries the exact moment (weekday at the target time) as an ISO 8601 string with offset, so no timestamp here is ambiguous about which day it means. Read-only: generating the draft is generate-client-update, sending it is mark-update-sent.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, ClientUpdateService $updates): Response
    {
        $validated = $request->validate([
            'only_due' => 'nullable|boolean',
        ]);

        $queue = $updates->queue();
        $totalActive = $queue->count();
        $dueCount = $queue->filter(fn (ClientUpdateQueueEntry $entry): bool => $entry->urgency->isDue())->count();

        if ($validated['only_due'] ?? false) {
            $queue = $queue->filter(fn (ClientUpdateQueueEntry $entry): bool => $entry->urgency->isDue())->values();
        }

        return Response::text(json_encode([
            'queue' => $queue->map(fn (ClientUpdateQueueEntry $entry, int $index): array => [
                'position' => $index + 1,
                'client_id' => $entry->client->id,
                'client_slug' => $entry->client->slug,
                'client' => $entry->client->name,
                'channel' => $entry->client->channel->value,
                'urgency' => $entry->urgency->value,
                'urgency_detail' => $entry->urgencyPhrase(),
                'due_at' => $entry->dueAt->toIso8601String(),
                'days_late' => $entry->daysLate(),
                'days_until' => $entry->daysUntil(),
                'last_sent_at' => $entry->lastSentAt?->toIso8601String(),
                'draft_id' => $entry->draft?->id,
                'draft_has_manual_edits' => $entry->draft?->hasManualEdits() ?? false,
            ])->all(),
            'due_count' => $dueCount,
            'total_active_clients' => $totalActive,
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
            'only_due' => $schema->boolean()->description('Return only the clients whose update is overdue or due today. Optional; due_count is reported regardless.'),
        ];
    }
}
