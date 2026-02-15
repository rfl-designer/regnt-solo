<?php

namespace App\Console\Commands;

use App\Models\WeeklyReview;
use Carbon\Carbon;
use Illuminate\Console\Command;

class WeeklyReviewCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soloboard:weekly-review {--week= : Data da semana (YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera a Weekly Review para a semana especificada ou atual';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $weekOption = $this->option('week');

        if ($weekOption !== null) {
            try {
                $date = Carbon::parse($weekOption);
            } catch (\Exception) {
                $this->error("Data invalida: {$weekOption}. Use o formato YYYY-MM-DD.");

                return self::FAILURE;
            }
        } else {
            $date = Carbon::now();
        }

        $review = WeeklyReview::getOrCreateForWeek($date);

        $weekStart = $review->week_start->format('d/m');
        $weekEnd = $review->week_end->format('d/m');
        $completedCount = $review->completedTasks()->count();
        $totalHours = $review->totalHours();
        $hours = (int) floor($totalHours);
        $minutes = (int) round(($totalHours - $hours) * 60);

        $this->info('Weekly Review gerada com sucesso!');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['Semana', "{$weekStart} - {$weekEnd}"],
                ['Tasks completadas', (string) $completedCount],
                ['Horas totais', "{$hours}h {$minutes}m"],
            ],
        );

        return self::SUCCESS;
    }
}
