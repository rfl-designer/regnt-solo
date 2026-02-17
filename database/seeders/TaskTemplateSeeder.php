<?php

namespace Database\Seeders;

use App\Models\TaskTemplate;
use Illuminate\Database\Seeder;

class TaskTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Code Review',
                'slug' => 'code-review',
                'description' => "## Checklist\n\n- [ ] Verificar lógica de negócio\n- [ ] Revisar tratamento de erros\n- [ ] Checar testes\n- [ ] Validar performance\n- [ ] Aprovar ou solicitar mudanças",
                'default_priority' => 'high',
                'default_estimated_minutes' => 30,
                'icon' => 'eye',
                'color' => 'blue',
                'is_system' => true,
            ],
            [
                'name' => 'Deploy Checklist',
                'slug' => 'deploy-checklist',
                'description' => "## Pré-Deploy\n\n- [ ] Testes passando\n- [ ] Migrations revisadas\n- [ ] Variáveis de ambiente atualizadas\n- [ ] Backup do banco\n\n## Deploy\n\n- [ ] Rodar migrations\n- [ ] Limpar cache\n- [ ] Verificar logs\n\n## Pós-Deploy\n\n- [ ] Testar funcionalidades críticas\n- [ ] Monitorar erros",
                'default_priority' => 'urgent',
                'default_estimated_minutes' => 45,
                'icon' => 'rocket-launch',
                'color' => 'red',
                'is_system' => true,
            ],
            [
                'name' => 'Bug Investigation',
                'slug' => 'bug-investigation',
                'description' => "## Reprodução\n\n- [ ] Consegui reproduzir o bug\n- [ ] Ambiente: \n- [ ] Passos para reproduzir:\n\n## Investigação\n\n- [ ] Analisar logs\n- [ ] Identificar causa raiz\n- [ ] Verificar se há issues relacionadas\n\n## Solução\n\n- [ ] Propor correção\n- [ ] Escrever testes\n- [ ] Documentar solução",
                'default_priority' => 'high',
                'default_estimated_minutes' => 60,
                'icon' => 'bug-ant',
                'color' => 'orange',
                'is_system' => true,
            ],
            [
                'name' => 'Daily Standup',
                'slug' => 'daily-standup',
                'description' => "## O que fiz ontem?\n\n- \n\n## O que vou fazer hoje?\n\n- \n\n## Bloqueios?\n\n- ",
                'default_priority' => 'medium',
                'default_estimated_minutes' => 15,
                'icon' => 'chat-bubble-left-right',
                'color' => 'green',
                'is_system' => true,
            ],
            [
                'name' => 'Sprint Planning',
                'slug' => 'sprint-planning',
                'description' => "## Objetivos da Sprint\n\n- \n\n## Tasks selecionadas\n\n- \n\n## Estimativa total\n\n- Horas: \n- Pontos: \n\n## Riscos identificados\n\n- ",
                'default_priority' => 'medium',
                'default_estimated_minutes' => 60,
                'icon' => 'clipboard-document-list',
                'color' => 'purple',
                'is_system' => true,
            ],
            [
                'name' => 'Feature Research',
                'slug' => 'feature-research',
                'description' => "## Objetivo\n\nDescrever o que precisa ser pesquisado.\n\n## Perguntas a responder\n\n- \n\n## Recursos consultados\n\n- \n\n## Conclusões\n\n- \n\n## Próximos passos\n\n- ",
                'default_priority' => 'medium',
                'default_estimated_minutes' => 90,
                'icon' => 'magnifying-glass',
                'color' => 'zinc',
                'is_system' => true,
            ],
        ];

        foreach ($templates as $template) {
            TaskTemplate::query()->updateOrCreate(
                ['slug' => $template['slug']],
                $template
            );
        }
    }
}
