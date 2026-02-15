<?php

namespace App\Mcp\Tools;

use App\Services\AnalyticsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetAnalyticsTool extends Tool
{
    protected string $name = 'get-analytics';

    protected string $description = 'Returns personal analytics data. If a specific metric is provided, returns only that metric. Otherwise, returns a summary with streaks, focus_ratio, velocity_last_week, and health_scores.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:7d,14d,30d,4w,12w,6m',
            'metric' => 'nullable|string|in:heatmap,cycle_time,focus_ratio,health_scores,velocity,streaks,patterns',
        ]);

        $period = $validated['period'] ?? '7d';
        $metric = $validated['metric'] ?? null;
        $service = app(AnalyticsService::class);

        if ($metric !== null) {
            $data = $this->getSpecificMetric($service, $metric, $period);
        } else {
            $data = $this->getSummary($service, $period);
        }

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()
                ->enum(['7d', '14d', '30d', '4w', '12w', '6m'])
                ->description('Time period for analytics. Default: 7d.')
                ->default('7d'),
            'metric' => $schema->string()
                ->enum(['heatmap', 'cycle_time', 'focus_ratio', 'health_scores', 'velocity', 'streaks', 'patterns'])
                ->description('Specific metric to retrieve. If omitted, returns a summary.'),
        ];
    }

    /**
     * Get a specific metric from the analytics service.
     *
     * @return array<string, mixed>
     */
    private function getSpecificMetric(AnalyticsService $service, string $metric, string $period): array
    {
        return match ($metric) {
            'heatmap' => ['metric' => 'heatmap', 'data' => $service->contributionHeatmap($this->periodToWeeks($period))],
            'cycle_time' => ['metric' => 'cycle_time', 'data' => $service->cycleTime($period)],
            'focus_ratio' => ['metric' => 'focus_ratio', 'data' => $service->focusRatio($period)],
            'health_scores' => ['metric' => 'health_scores', 'data' => $service->projectHealthScores()],
            'velocity' => ['metric' => 'velocity', 'data' => $service->velocityTrend($this->periodToWeeks($period))],
            'streaks' => ['metric' => 'streaks', 'data' => $service->streaks()],
            'patterns' => ['metric' => 'patterns', 'data' => $service->productivityPatterns($this->periodToWeeks($period))],
            default => ['error' => 'Unknown metric'],
        };
    }

    /**
     * Get a summary of key analytics metrics.
     *
     * @return array<string, mixed>
     */
    private function getSummary(AnalyticsService $service, string $period): array
    {
        $velocity = $service->velocityTrend($this->periodToWeeks($period));
        $lastWeek = ! empty($velocity) ? end($velocity) : null;

        return [
            'period' => $period,
            'streaks' => $service->streaks(),
            'focus_ratio' => $service->focusRatio($period),
            'velocity_last_week' => $lastWeek ? [
                'completed' => $lastWeek['completed_count'],
                'created' => $lastWeek['created_count'],
            ] : null,
            'health_scores' => $service->projectHealthScores(),
        ];
    }

    /**
     * Convert a period string to weeks.
     */
    private function periodToWeeks(string $period): int
    {
        return match ($period) {
            '7d' => 1,
            '14d' => 2,
            '30d' => 4,
            '4w' => 4,
            '12w' => 12,
            '6m' => 26,
            default => 1,
        };
    }
}
