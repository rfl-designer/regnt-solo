<?php

namespace App\Services;

use App\Models\Activity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAssistantService
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';

    private const string API_VERSION = '2023-06-01';

    private const int TIMEOUT_SECONDS = 30;

    private const string BASE_SYSTEM_PROMPT = 'You are a productivity coach for a solo developer using a personal task management tool called SoloBoard. Your role is to help them prioritize tasks, manage their backlog, and identify productivity patterns. Always respond with valid JSON.';

    /**
     * Check if AI features are enabled and properly configured.
     */
    public function isEnabled(): bool
    {
        return config('soloboard.ai_enabled') === true
            && ! empty(config('soloboard.ai_api_key'));
    }

    /**
     * Analyze backlog tasks and suggest actions for each.
     *
     * @param  Collection<int, Activity>  $tasks
     * @return array<int, array{task_id: int, action: string, reason: string, suggested_service_class: string|null, suggested_project: string|null}>
     */
    public function analyzeBacklog(Collection $tasks): array
    {
        if (! $this->isEnabled() || $tasks->isEmpty()) {
            return [];
        }

        $prompts = $this->buildBacklogPrompt($tasks);

        $response = $this->callAnthropic($prompts['system'], $prompts['user']);

        return $this->parseJsonResponse($response);
    }

    /**
     * Detect productivity patterns from weekly data.
     *
     * @param  array<string, mixed>  $weeklyData
     * @return array<int, array{type: string, message: string, severity: string, action_url: string|null, action_label: string|null}>
     */
    public function detectPatterns(array $weeklyData): array
    {
        if (! $this->isEnabled() || empty($weeklyData)) {
            return [];
        }

        $prompts = $this->buildPatternsPrompt($weeklyData);

        $response = $this->callAnthropic($prompts['system'], $prompts['user']);

        return $this->parseJsonResponse($response);
    }

    /**
     * Refine the wording of a client update draft without touching its facts.
     *
     * Here the AI is a copy editor, never a writer: it may rework tone,
     * fluency, concision and the transitions between sentences, and nothing
     * else. Adding, removing or altering an item, a state, a date or a
     * commitment is out of contract — and so is summarising or reordering the
     * blocks the deterministic generator produced.
     *
     * Returns an empty string whenever the refinement cannot be trusted — AI
     * off, empty draft, API failure or an empty answer — so the caller simply
     * keeps the draft it already had.
     */
    public function refineUpdate(string $draft): string
    {
        if (! $this->isEnabled() || trim($draft) === '') {
            return '';
        }

        $prompts = $this->buildRefineUpdatePrompt($draft);

        $response = $this->callAnthropic($prompts['system'], $prompts['user']);

        return $this->parseTextResponse($response);
    }

    /**
     * Call the Anthropic API with the given prompts.
     *
     * @return array<string, mixed>
     */
    private function callAnthropic(string $systemPrompt, string $userMessage): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => config('soloboard.ai_api_key'),
                'anthropic-version' => self::API_VERSION,
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::API_URL, [
                    'model' => config('soloboard.ai_model'),
                    'max_tokens' => 2048,
                    'system' => $systemPrompt,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $userMessage,
                        ],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('Anthropic API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('Anthropic API call failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build system and user prompts for backlog analysis.
     *
     * @param  Collection<int, Activity>  $tasks
     * @return array{system: string, user: string}
     */
    private function buildBacklogPrompt(Collection $tasks): array
    {
        $systemPrompt = self::BASE_SYSTEM_PROMPT.' Focus on analyzing backlog and inbox tasks. Suggest concrete actions for each task: archive (if stale or irrelevant), prioritize (if important), or estimate (if missing time estimates). Respond with a JSON array where each item has: task_id (int), action (string: "archive", "prioritize", or "estimate"), reason (string), suggested_service_class (string or null: "emergency", "fixed_date", "standard", "intangible" — only use "fixed_date" when a due date is known), and suggested_project (string or null).';

        $taskData = $tasks->map(fn ($task) => [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'service_class' => $task->service_class->value,
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'project' => $task->project?->name,
            'created_at' => $task->created_at->toDateString(),
        ])->values()->toArray();

        $userMessage = 'Analyze these backlog/inbox tasks and suggest actions: '.json_encode($taskData, JSON_THROW_ON_ERROR)."\n\nReturn only a JSON array.";

        return ['system' => $systemPrompt, 'user' => $userMessage];
    }

    /**
     * Build system and user prompts for pattern detection.
     *
     * @param  array<string, mixed>  $weeklyData
     * @return array{system: string, user: string}
     */
    private function buildPatternsPrompt(array $weeklyData): array
    {
        $systemPrompt = self::BASE_SYSTEM_PROMPT.' Focus on detecting productivity patterns: abandoned projects (started but no activity), over-commitment (too many tasks in progress), best productive hours, and recurring blockers. Respond with a JSON array where each item has: type (string: "abandoned_project", "over_commitment", "productive_hours", "blocker", "positive_trend"), message (string describing the pattern), severity (string: "info", "warning", "critical"), action_url (string or null), and action_label (string or null).';

        $userMessage = 'Here is my weekly productivity data: '.json_encode($weeklyData, JSON_THROW_ON_ERROR)."\n\nDetect patterns and provide actionable insights. Return only a JSON array.";

        return ['system' => $systemPrompt, 'user' => $userMessage];
    }

    /**
     * Build system and user prompts for refining a client update draft.
     *
     * The system prompt is deliberately not built on top of
     * {@see BASE_SYSTEM_PROMPT}: this is the one call that must answer with
     * prose instead of JSON, and the coach persona would invite exactly the
     * suggestions the copy editor contract forbids.
     *
     * @return array{system: string, user: string}
     */
    private function buildRefineUpdatePrompt(string $draft): array
    {
        $systemPrompt = <<<'PROMPT'
            You are a copy editor for the weekly client update of a solo developer, written in Brazilian Portuguese. You are not a writer: you never decide what the update says, only how it reads.

            You may only improve tone, fluency, concision and the transitions between sentences.

            Hard rules — breaking any of them makes your answer useless:
            - Never add, remove or alter any item, state, date, number, name or commitment. Every fact in the draft must survive untouched, and no fact may appear that was not already there.
            - Never summarise, merge or drop content, and never reorder the blocks, their headings or the items inside them.
            - Keep the structure and the Markdown of the draft, which has to degrade well in any channel (WhatsApp, e-mail, Slack): short paragraphs and simple lists, no tables, no headings the draft does not already use.
            - Keep the tone Brazilian Portuguese, professional and close — the voice of someone who works with the client, neither corporate nor casual.
            - The draft may carry manual edits by the developer. Treat them like the rest: polish the wording, keep the meaning.

            Answer with the refined update text only. No preamble, no explanation, no code fences.
            PROMPT;

        $userMessage = "Refine este rascunho de update semanal:\n\n".$draft;

        return ['system' => $systemPrompt, 'user' => $userMessage];
    }

    /**
     * Extract the plain text of the Anthropic API response content.
     *
     * @param  array<string, mixed>  $response
     */
    private function parseTextResponse(array $response): string
    {
        $content = $response['content'][0]['text'] ?? '';

        return is_string($content) ? trim($content) : '';
    }

    /**
     * Extract and parse JSON from the Anthropic API response content.
     *
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function parseJsonResponse(array $response): array
    {
        if (empty($response)) {
            return [];
        }

        try {
            $content = $response['content'][0]['text'] ?? '';

            if (empty($content)) {
                return [];
            }

            // Try to extract JSON array from the response
            if (preg_match('/\[.*\]/s', $content, $matches)) {
                $decoded = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);

                return is_array($decoded) ? $decoded : [];
            }

            return [];
        } catch (\JsonException $e) {
            Log::warning('Failed to parse Anthropic JSON response', [
                'message' => $e->getMessage(),
                'content' => $content ?? '',
            ]);

            return [];
        }
    }
}
