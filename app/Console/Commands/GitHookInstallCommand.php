<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GitHookInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soloboard:git-hook-install
        {path? : Caminho do repositório Git (default: diretório atual)}
        {--url= : URL do SoloBoard (default: APP_URL)}
        {--key= : API key para autenticação MCP}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Instala o git hook post-commit para rastrear commits no SoloBoard';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $repoPath = $this->argument('path') ?? getcwd();
        $gitDir = rtrim($repoPath, '/').'/.git';

        if (! is_dir($gitDir)) {
            $this->error("Diretório Git não encontrado em: {$gitDir}");

            return self::FAILURE;
        }

        $hooksDir = $gitDir.'/hooks';
        if (! is_dir($hooksDir)) {
            mkdir($hooksDir, 0755, true);
        }

        $hookPath = $hooksDir.'/post-commit';
        $url = $this->option('url') ?: config('app.url');
        $key = $this->option('key') ?: '';

        $hookContent = $this->generateHookScript($url, $key);

        file_put_contents($hookPath, $hookContent);
        chmod($hookPath, 0755);

        $this->info('Git hook post-commit instalado com sucesso!');
        $this->table(
            ['Configuração', 'Valor'],
            [
                ['Hook', $hookPath],
                ['SoloBoard URL', $url],
                ['API Key', $key ? '***'.substr($key, -4) : 'Não configurada'],
            ],
        );

        return self::SUCCESS;
    }

    private function generateHookScript(string $url, string $key): string
    {
        return <<<BASH
#!/bin/bash
# SoloBoard post-commit hook
# Automatically logs commits referencing [SB-{id}] to SoloBoard

COMMIT_MSG=\$(git log -1 --pretty=%B)
# Extract task ID from [SB-{id}] or #SB-{id} format (POSIX-compatible for macOS/Linux)
TASK_ID=\$(echo "\$COMMIT_MSG" | sed -n 's/.*\[SB-\([0-9][0-9]*\)\].*/\1/p' | head -1)

if [ -z "\$TASK_ID" ]; then
    TASK_ID=\$(echo "\$COMMIT_MSG" | sed -n 's/.*#SB-\([0-9][0-9]*\).*/\1/p' | head -1)
fi

if [ -n "\$TASK_ID" ]; then
    HASH=\$(git rev-parse HEAD)
    SHORT_HASH=\$(git rev-parse --short HEAD)
    FILES=\$(git diff --numstat HEAD~1 HEAD 2>/dev/null | wc -l | tr -d ' ')
    INSERTIONS=\$(git diff --numstat HEAD~1 HEAD 2>/dev/null | awk '{s+=\$1}END{print s+0}')
    DELETIONS=\$(git diff --numstat HEAD~1 HEAD 2>/dev/null | awk '{s+=\$2}END{print s+0}')
    COMMITTED_AT=\$(git log -1 --format=%aI)

    # Escape message for JSON
    ESCAPED_MSG=\$(echo "\$COMMIT_MSG" | head -1 | sed 's/\\\\/\\\\\\\\/g; s/"/\\\\"/g')

    JSON=\$(cat <<EOF
{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/call",
    "params": {
        "name": "log-commits",
        "arguments": {
            "task_id": \$TASK_ID,
            "commits": [{
                "hash": "\$HASH",
                "message": "\$ESCAPED_MSG",
                "files_changed": \$FILES,
                "insertions": \$INSERTIONS,
                "deletions": \$DELETIONS,
                "committed_at": "\$COMMITTED_AT"
            }]
        }
    }
}
EOF
)

    SOLOBOARD_URL="{$url}"
    SOLOBOARD_KEY="{$key}"

    if [ -n "\$SOLOBOARD_KEY" ]; then
        curl -s -X POST "\$SOLOBOARD_URL/mcp/soloboard" \\
            -H "Content-Type: application/json" \\
            -H "Authorization: Bearer \$SOLOBOARD_KEY" \\
            -d "\$JSON" > /dev/null 2>&1
    else
        curl -s -X POST "\$SOLOBOARD_URL/mcp/soloboard" \\
            -H "Content-Type: application/json" \\
            -d "\$JSON" > /dev/null 2>&1
    fi

    echo "[SoloBoard] Commit \$SHORT_HASH logged for task SB-\$TASK_ID"
fi
BASH;
    }
}
