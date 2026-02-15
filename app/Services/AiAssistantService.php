<?php

namespace App\Services;

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
     * Suggest a daily plan based on available tasks and completion history.
     *
     * @param  Collection<int, \App\Models\Task>  $tasks
     * @param  array<string, mixed>  $history
     * @return array<int, array{task_id: int, reason: string, priority_score: int}>
     */
    public function suggestDailyPlan(Collection $tasks, array $history = []): array
    {
        if (! $this->isEnabled() || $tasks->isEmpty()) {
            return [];
        }

        $prompts = $this->buildDailyPlanPrompt($tasks, $history);

        $response = $this->callAnthropic($prompts['system'], $prompts['user']);

        return $this->parseJsonResponse($response);
    }

    /**
     * Analyze backlog tasks and suggest actions for each.
     *
     * @param  Collection<int, \App\Models\Task>  $tasks
     * @return array<int, array{task_id: int, action: string, reason: string, suggested_priority: string|null, suggested_project: string|null}>
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
     * Build system and user prompts for daily plan suggestions.
     *
     * @param  Collection<int, \App\Models\Task>  $tasks
     * @param  array<string, mixed>  $history
     * @return array{system: string, user: string}
     */
    private function buildDailyPlanPrompt(Collection $tasks, array $history): array
    {
        $systemPrompt = self::BASE_SYSTEM_PROMPT.' Focus on suggesting the most impactful tasks for today\'s work session. Consider task priority, due dates, estimated time, and the developer\'s recent completion patterns. Respond with a JSON array where each item has: task_id (int), reason (string explaining why this task should be prioritized), and priority_score (int from 1-100, higher means more important).';

        $taskData = $tasks->map(fn ($task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_date' => $task->due_date?->toDateString(),
            'estimated_minutes' => $task->estimated_minutes,
            'project' => $task->project?->name,
        ])->values()->toArray();

        $userMessage = 'Here are my available tasks: '.json_encode($taskData, JSON_THROW_ON_ERROR);

        if (! empty($history)) {
            $userMessage .= "\n\nHere is my recent completion history: ".json_encode($history, JSON_THROW_ON_ERROR);
        }

        $userMessage .= "\n\nSuggest the best tasks for my daily plan today. Return only a JSON array.";

        return ['system' => $systemPrompt, 'user' => $userMessage];
    }

    /**
     * Build system and user prompts for backlog analysis.
     *
     * @param  Collection<int, \App\Models\Task>  $tasks
     * @return array{system: string, user: string}
     */
    private function buildBacklogPrompt(Collection $tasks): array
    {
        $systemPrompt = self::BASE_SYSTEM_PROMPT.' Focus on analyzing backlog and inbox tasks. Suggest concrete actions for each task: archive (if stale or irrelevant), prioritize (if important), or estimate (if missing time estimates). Respond with a JSON array where each item has: task_id (int), action (string: "archive", "prioritize", or "estimate"), reason (string), suggested_priority (string or null: "urgent", "high", "medium", "low"), and suggested_project (string or null).';

        $taskData = $tasks->map(fn ($task) => [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
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
