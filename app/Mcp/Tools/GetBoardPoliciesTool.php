<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Services\BoardPolicyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The board's policies, as an AI client reads them (issue #154).
 *
 * Renders {@see BoardPolicyService} — the same service the Kanban's policy
 * panel renders — so an agent asking "posso puxar isso?" or "isso está
 * feito?" answers from the very text and the very limits the person
 * looking at the board sees.
 *
 * Read-only on all three parts, including the written sections: editing a
 * policy is a deliberate human act with a note explaining why, and an
 * agent silently appending a version would turn the history into noise
 * exactly where its value is.
 */
#[IsReadOnly]
class GetBoardPoliciesTool extends Tool
{
    protected string $name = 'get-board-policies';

    protected string $description = 'Returns the board policies in three parts. `mechanics` are computed from the real state on every call, never written text: the Fazendo WIP limit with the current count, the single-Emergência rule with whoever holds the slot, the degraus of the pull queue in order, and the risk window in days with its source (the measured SLE once the baseline is big enough, the configured value until then). `sections` are the three written and versioned policies — definition_of_done, definition_of_ready, working_agreements — each with the body in force (markdown), the optional note saying why it changed, when it was written and how many versions exist; the table is append-only, so a body that is null means nothing was ever written for that key. `response_agreements` lists the active clients that have a response agreement (read from the client record, edited only in the Clients page) plus `clients_without_agreement`, the active clients with none. Everything here is read-only: writing a policy version is a deliberate human act.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, BoardPolicyService $policies): Response
    {
        $payload = [
            'mechanics' => $policies->mechanics(),
            'sections' => collect($policies->sections())
                ->map(fn (array $section): array => [
                    'key' => $section['key']->value,
                    'label' => $section['label'],
                    'body' => $section['version']?->body,
                    'note' => $section['version']?->note,
                    'updated_at' => $section['version']?->created_at?->toDateTimeString(),
                    'versions_count' => $section['versions_count'],
                ])
                ->all(),
            'response_agreements' => $policies->responseAgreements()
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'slug' => $client->slug,
                    'color' => $client->color,
                    'agreement' => $client->response_agreement,
                ])
                ->values()
                ->all(),
            'clients_without_agreement' => $policies->clientsWithoutAgreement()
                ->map(fn (Client $client): array => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'slug' => $client->slug,
                ])
                ->values()
                ->all(),
        ];

        return Response::text(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
