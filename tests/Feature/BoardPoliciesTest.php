<?php

use App\Enums\ActivityStatus;
use App\Enums\PolicyKey;
use App\Models\Activity;
use App\Models\Client;
use App\Models\PolicyVersion;
use App\Services\BoardPolicyService;
use Database\Seeders\PolicyVersionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * As políticas do quadro (issue #154): as mecânicas derivadas do estado
 * real, o log append-only das três seções escritas e a leitura dos acordos
 * de resposta dos clientes.
 */
function policies(): BoardPolicyService
{
    return app(BoardPolicyService::class);
}

/**
 * A mecânica de uma chave, do jeito que o painel a lê.
 *
 * @return array<string, mixed>
 */
function mechanic(string $key): array
{
    return collect(policies()->mechanics())->firstWhere('key', $key);
}

test('as mecânicas saem da config: mudar o limite de WIP muda o painel', function () {
    expect(mechanic('wip_limit_doing')['statement'])->toContain('No máximo 2 itens');

    config()->set('soloboard.wip_limit_doing', 5);

    expect(mechanic('wip_limit_doing')['statement'])->toContain('No máximo 5 itens');
});

test('a mecânica do WIP mostra quantos itens estão em Fazendo agora', function () {
    Activity::factory()->issue()->count(2)->create(['status' => ActivityStatus::Doing]);

    expect(mechanic('wip_limit_doing')['current'])->toBe('2/2 agora');
});

test('a janela de risco sai do serviço da fila e diz de onde veio', function () {
    config()->set('soloboard.fixed_date_risk_days', 9);

    $riskWindow = mechanic('risk_window');

    expect($riskWindow['statement'])->toContain('9 dias')
        ->and($riskWindow['current'])->toBe('Fonte: config');
});

test('a ordem de puxar é renderizada dos degraus da fila, em ordem', function () {
    expect(mechanic('pull_order')['statement'])
        ->toContain('1. Emergência')
        ->toContain('2. Data fixa em risco')
        ->toContain('3. Ordem de chegada');
});

test('a mecânica da Emergência nomeia quem está segurando a vaga', function () {
    expect(mechanic('single_emergency')['current'])->toBe('Nenhuma Emergência ativa');

    Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create(['title' => 'Restaurar API']);

    expect(mechanic('single_emergency')['current'])->toBe('Ativa: Restaurar API');
});

test('salvar uma política insere uma versão e nunca sobrescreve a anterior', function () {
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'Primeira versão');
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'Segunda versão', 'Passei a exigir teste');

    expect(PolicyVersion::query()->forKey(PolicyKey::DefinitionOfDone)->count())->toBe(2)
        ->and(PolicyVersion::current(PolicyKey::DefinitionOfDone)->body)->toBe('Segunda versão')
        ->and(PolicyVersion::current(PolicyKey::DefinitionOfDone)->note)->toBe('Passei a exigir teste');

    // A anterior continua legível, que é a única razão de versionar.
    expect(PolicyVersion::history(PolicyKey::DefinitionOfDone)->pluck('body')->all())
        ->toBe(['Segunda versão', 'Primeira versão']);
});

test('a nota é opcional e uma nota em branco vira ausência de nota', function () {
    $version = PolicyVersion::record(PolicyKey::WorkingAgreements, 'Texto', '   ');

    expect($version->note)->toBeNull();
});

test('cada seção é uma linha de versões própria', function () {
    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'Feito');
    PolicyVersion::record(PolicyKey::DefinitionOfReady, 'Pronto');

    expect(PolicyVersion::current(PolicyKey::DefinitionOfDone)->body)->toBe('Feito')
        ->and(PolicyVersion::current(PolicyKey::DefinitionOfReady)->body)->toBe('Pronto')
        ->and(PolicyVersion::current(PolicyKey::WorkingAgreements))->toBeNull();
});

test('as três seções aparecem mesmo sem nada escrito', function () {
    $sections = collect(policies()->sections());

    expect($sections)->toHaveCount(3)
        ->and($sections->pluck('key')->all())->toBe(PolicyKey::cases())
        ->and($sections->every(fn (array $section): bool => $section['version'] === null))->toBeTrue()
        ->and($sections->every(fn (array $section): bool => $section['versions_count'] === 0))->toBeTrue();
});

test('o seeder grava a v1 das três seções com a nota do método', function () {
    (new PolicyVersionSeeder)->run();

    foreach (PolicyKey::cases() as $key) {
        $version = PolicyVersion::current($key);

        expect($version)->not->toBeNull()
            ->and($version->note)->toBe('Padrão inicial do método')
            ->and($version->body)->not->toBe('');
    }

    expect(PolicyVersion::query()->count())->toBe(3);
});

test('rodar o seeder de novo não enterra o texto que o usuário escreveu', function () {
    (new PolicyVersionSeeder)->run();

    PolicyVersion::record(PolicyKey::DefinitionOfDone, 'Minha definição');

    (new PolicyVersionSeeder)->run();

    expect(PolicyVersion::current(PolicyKey::DefinitionOfDone)->body)->toBe('Minha definição')
        ->and(PolicyVersion::query()->forKey(PolicyKey::DefinitionOfDone)->count())->toBe(2);
});

test('os acordos de resposta são lidos dos clientes ativos que os têm', function () {
    $withAgreement = Client::factory()->create([
        'name' => 'Acme',
        'is_active' => true,
        'response_agreement' => 'Respondo em até 1 dia útil',
    ]);
    Client::factory()->create(['name' => 'Sem acordo', 'is_active' => true, 'response_agreement' => null]);
    Client::factory()->create(['name' => 'Inativo', 'is_active' => false, 'response_agreement' => 'Ignorado']);

    $agreements = policies()->responseAgreements();

    expect($agreements->pluck('id')->all())->toBe([$withAgreement->id]);
});

test('a cutucada conta só os clientes ativos sem acordo', function () {
    Client::factory()->create(['is_active' => true, 'response_agreement' => 'Em 1 dia']);
    Client::factory()->create(['is_active' => true, 'response_agreement' => null]);
    Client::factory()->create(['is_active' => true, 'response_agreement' => '']);
    Client::factory()->create(['is_active' => false, 'response_agreement' => null]);

    expect(policies()->clientsWithoutAgreement())->toHaveCount(2);
});

/**
 * Regressões do review do #154.
 */
test('uma versão registrada não pode ser sobrescrita nem apagada', function () {
    $version = PolicyVersion::record(PolicyKey::DefinitionOfDone, 'v1', 'Padrão inicial do método');

    // Update pela instância.
    expect(fn () => $version->update(['body' => 'sobrescrita']))
        ->toThrow(RuntimeException::class);

    // save() sobre uma instância existente é a mesma porta.
    $version->body = 'sobrescrita';
    expect(fn () => $version->save())->toThrow(RuntimeException::class);

    // Delete pela instância e em massa.
    expect(fn () => $version->fresh()->delete())->toThrow(RuntimeException::class);
    expect(fn () => PolicyVersion::query()->forKey(PolicyKey::DefinitionOfDone)->delete())
        ->toThrow(RuntimeException::class);

    // Update em massa não passa nem pelos eventos do model.
    expect(fn () => PolicyVersion::query()->forKey(PolicyKey::DefinitionOfDone)->update(['body' => 'sobrescrita']))
        ->toThrow(RuntimeException::class);

    $current = PolicyVersion::current(PolicyKey::DefinitionOfDone);

    expect(PolicyVersion::query()->count())->toBe(1)
        ->and($current->body)->toBe('v1')
        ->and($current->id)->toBe($version->id);
});

test('a contagem de versões é agregada no banco, não hidratando o histórico', function () {
    foreach (range(1, 12) as $i) {
        PolicyVersion::record(PolicyKey::DefinitionOfDone, "v{$i}");
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'policy_versions')) {
            $queries[] = $query->sql;
        }
    });

    $sections = collect(policies()->sections())->keyBy(fn (array $s): string => $s['key']->value);

    expect($sections['definition_of_done']['versions_count'])->toBe(12);

    // Toda leitura da tabela ou é agregada, ou é a vigente (limit 1).
    foreach ($queries as $sql) {
        expect(str_contains($sql, 'count(*)') || str_contains($sql, 'limit 1'))
            ->toBeTrue("Consulta hidratando o histórico inteiro: {$sql}");
    }
});

test('acordo de resposta só com espaços conta como ausente', function () {
    Client::factory()->create(['name' => 'Só espaços', 'is_active' => true, 'response_agreement' => '   ']);

    expect(policies()->responseAgreements())->toHaveCount(0)
        ->and(policies()->clientsWithoutAgreement()->pluck('name')->all())->toBe(['Só espaços']);
});
