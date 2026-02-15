<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GitHookRemoveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soloboard:git-hook-remove
        {path? : Caminho do repositório Git (default: diretório atual)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove o git hook post-commit do SoloBoard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $repoPath = $this->argument('path') ?? getcwd();
        $hookPath = rtrim($repoPath, '/').'/.git/hooks/post-commit';

        if (! file_exists($hookPath)) {
            $this->warn('Nenhum hook post-commit encontrado.');

            return self::SUCCESS;
        }

        $content = file_get_contents($hookPath);

        if (! str_contains($content, 'SoloBoard post-commit hook')) {
            $this->warn('O hook post-commit existente não é do SoloBoard. Nenhuma ação tomada.');

            return self::FAILURE;
        }

        unlink($hookPath);

        $this->info('Git hook post-commit removido com sucesso!');

        return self::SUCCESS;
    }
}
