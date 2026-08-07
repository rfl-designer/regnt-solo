<?php

namespace App\Mcp\Concerns;

use App\Exceptions\DomainRefusal;
use App\Exceptions\SingleActiveEmergencyException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

/**
 * Turns the domain guards' refusals into MCP responses, in one place.
 *
 * Every tool that saves an `Activity` catches {@see DomainRefusal} — the
 * marker interface all of them implement — and hands it here, so a client
 * gets the same answer to the same "não" regardless of which tool it
 * called. The divergence the review found (update-epic enumerating only
 * waiting/WIP and letting an Emergência conflict escape untranslated) is
 * structurally impossible once nobody enumerates.
 */
trait TranslatesDomainRefusals
{
    /**
     * Translate a domain refusal into the MCP response for it.
     *
     * A second Emergência gets a genuinely structured error: the human
     * message stays in `content[].text` and the active Emergência plus the
     * swap recipe travel in `structuredContent`, so a client reads fields
     * instead of parsing JSON out of prose. Every other refusal is a plain
     * error carrying its canonical PT-BR message.
     */
    protected function refusalResponse(DomainRefusal&Throwable $e): Response|ResponseFactory
    {
        if (! $e instanceof SingleActiveEmergencyException) {
            return Response::error($e->getMessage());
        }

        return Response::make(Response::error($e->getMessage()))
            ->withStructuredContent([
                'error' => 'single_active_emergency',
                'message' => $e->getMessage(),
                'active_emergency' => $e->activeEmergencyContext(),
                // Spelled out because there is deliberately no `force`
                // parameter: swapping the Emergência is always a decision
                // about which of the two it is, never a flag.
                'how_to_swap' => 'There is no force parameter. To swap, make two calls: first demote the active emergency (service_class="standard" on id '
                    .$e->activeEmergency->id.'), then classify the new one as "emergency" with an emergency_reason.',
            ]);
    }
}
