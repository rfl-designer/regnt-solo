<?php

namespace Database\Seeders;

use App\Enums\ActivityPriority;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $project = Project::where('slug', 'soloboard')->first();

        if (! $project) {
            return;
        }

        $epics = [
            [
                'title' => 'Autenticação com 2FA',
                'slug' => 'auth-2fa',
                'priority' => ActivityPriority::High,
                'status' => ActivityStatus::Doing,
                'spec' => $this->getAuth2faSpec(),
                'tasks' => [
                    ['title' => 'Migration para two_factor_secret', 'status' => ActivityStatus::Done],
                    ['title' => 'Tela de configuração 2FA', 'status' => ActivityStatus::Done],
                    ['title' => 'Validação TOTP no login', 'status' => ActivityStatus::Doing],
                    ['title' => 'Recovery codes', 'status' => ActivityStatus::Todo],
                    ['title' => 'Testes de autenticação 2FA', 'status' => ActivityStatus::Backlog],
                ],
            ],
            [
                'title' => 'Dashboard de Analytics',
                'slug' => 'analytics-dashboard',
                'priority' => ActivityPriority::Medium,
                'status' => ActivityStatus::Todo,
                'spec' => $this->getAnalyticsSpec(),
                'tasks' => [
                    ['title' => 'Componente de heatmap', 'status' => ActivityStatus::Todo],
                    ['title' => 'Gráfico de velocity', 'status' => ActivityStatus::Backlog],
                    ['title' => 'Health score calculator', 'status' => ActivityStatus::Backlog],
                ],
            ],
            [
                'title' => 'Export de Relatórios',
                'slug' => 'export-reports',
                'priority' => ActivityPriority::Low,
                'status' => ActivityStatus::Backlog,
                'spec' => $this->getExportSpec(),
                'tasks' => [],
            ],
        ];

        foreach ($epics as $epicData) {
            $tasks = $epicData['tasks'];
            unset($epicData['tasks']);

            $epic = Activity::create([
                ...$epicData,
                'type' => ActivityType::Epic,
                'project_id' => $project->id,
            ]);

            foreach ($tasks as $index => $taskData) {
                Activity::create([
                    ...$taskData,
                    'type' => ActivityType::Task,
                    'parent_id' => $epic->id,
                    'project_id' => $project->id,
                    'priority' => ActivityPriority::Medium,
                    'sort_order' => $index,
                    'completed_at' => $taskData['status'] === ActivityStatus::Done ? now() : null,
                ]);
            }
        }
    }

    private function getAuth2faSpec(): string
    {
        return <<<'SPEC'
## User Story
Como usuário, quero configurar autenticação de dois fatores para aumentar a segurança da minha conta.

## Contexto
Atualmente o sistema usa apenas email/senha para login. Precisamos adicionar uma camada extra de segurança com TOTP.

## Critérios de Aceitação
- [ ] Usuário pode ativar 2FA nas configurações
- [ ] QR Code gerado para apps autenticadores
- [ ] Validação de código TOTP no login
- [ ] Recovery codes para emergências
- [ ] Opção de desativar 2FA

## Notas Técnicas
- Usar Laravel Fortify para 2FA
- Biblioteca: pragmarx/google2fa
- Armazenar secret criptografado
SPEC;
    }

    private function getAnalyticsSpec(): string
    {
        return <<<'SPEC'
## User Story
Como desenvolvedor solo, quero visualizar métricas de produtividade para entender meus padrões de trabalho.

## Contexto
O SoloBoard coleta dados de tempo e tarefas. Precisamos transformar isso em insights visuais.

## Critérios de Aceitação
- [ ] Heatmap de atividade estilo GitHub
- [ ] Gráfico de velocity (tasks/semana)
- [ ] Health score baseado em métricas
- [ ] Padrões de horário de trabalho
- [ ] Filtros por período

## Notas Técnicas
- Usar Flux charts
- Calcular métricas no backend (AnalyticsService)
- Cache de 1 hora para queries pesadas
SPEC;
    }

    private function getExportSpec(): string
    {
        return <<<'SPEC'
## User Story
Como desenvolvedor solo, quero exportar relatórios de tempo para compartilhar com clientes.

## Contexto
Preciso gerar relatórios de horas trabalhadas por projeto para faturamento.

## Critérios de Aceitação
- [ ] Export em PDF
- [ ] Export em CSV
- [ ] Filtro por projeto e período
- [ ] Totais por dia/semana/mês

## Notas Técnicas
- Usar dompdf para PDF
- League\Csv para CSV
- Job assíncrono para relatórios grandes
SPEC;
    }
}
