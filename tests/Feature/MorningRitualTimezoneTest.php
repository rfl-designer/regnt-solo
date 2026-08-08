<?php

use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\GetRitualStatusTool;
use App\Models\MorningRitual;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['soloboard.timezone' => 'America/Recife']);
});

/**
 * O dia do ritual é o dia do usuário (issue #147, review).
 *
 * A aplicação roda em UTC — e deve continuar rodando. Mas em UTC-3 o dia
 * UTC vira às 21h locais, então sem um fuso de negócio um ritual feito às
 * 21h30 de segunda seria arquivado como o de terça, e o badge voltaria a
 * pedir o ritual na mesma noite.
 */
test('the ritual day turns at local midnight, not at the UTC one', function () {
    // 21:30 em Recife = 00:30 UTC do dia seguinte: ainda é o mesmo dia
    // para quem está olhando o relógio da parede.
    $this->travelTo('2026-08-08 00:30:00'); // UTC

    $ritual = MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete('noite de sete');

    expect($ritual->date->toDateString())->toBe('2026-08-07')
        ->and(MorningRitual::completedToday())->toBeTrue()
        ->and(MorningRitual::businessToday()->toDateString())->toBe('2026-08-07');
});

test('crossing local midnight starts a new ritual day', function () {
    $this->travelTo('2026-08-08 00:30:00'); // 21:30 local, dia 07
    MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete(null);

    expect(MorningRitual::completedToday())->toBeTrue();

    $this->travelTo('2026-08-08 03:30:00'); // 00:30 local, dia 08

    expect(MorningRitual::businessToday()->toDateString())->toBe('2026-08-08')
        ->and(MorningRitual::completedToday())->toBeFalse();
});

test('the completion time is rendered in the business timezone', function () {
    $this->travelTo('2026-08-07 11:15:00'); // 08:15 em Recife

    $ritual = MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete(null);

    expect($ritual->completedAtLabel())->toBe('08:15')
        ->and($ritual->completed_at->toDateTimeString())->toBe('2026-08-07 11:15:00');
});

test('the wizard shows the local completion time', function () {
    $this->travelTo('2026-08-07 11:15:00');

    Livewire::test('pages::morning-ritual')->set('step', 6)->call('completeRitual');

    Livewire::test('pages::morning-ritual')->assertSee('Já concluído às 08:15');
});

test('the sidebar badge follows the local day, not the UTC one', function () {
    $this->travelTo('2026-08-08 00:30:00'); // 21:30 local
    MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete(null);

    Livewire::test('ritual-badge')->assertDontSee('hoje');

    $this->travelTo('2026-08-08 03:30:00'); // 00:30 local do dia seguinte

    Livewire::test('ritual-badge')->assertSee('hoje');
});

test('the badge re-checks the day on its own, for a session left open', function () {
    $this->travelTo('2026-08-08 00:30:00');
    MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete(null);

    $badge = Livewire::test('ritual-badge')->assertDontSee('hoje');

    // O poll de baixa frequência chama exatamente esta action; sem ela o
    // badge ficaria mostrando o estado de ontem até outro render.
    $this->travelTo('2026-08-08 03:30:00');

    $badge->call('refreshBadge')->assertSee('hoje');
});

test('get-ritual-status answers for the local day', function () {
    $this->travelTo('2026-08-08 00:30:00'); // 21:30 local do dia 07

    MorningRitual::getOrCreateForDate(MorningRitual::businessToday())->complete('feito à noite');

    $response = SoloBoardServer::tool(GetRitualStatusTool::class, []);
    $response->assertOk();
    $content = (new ReflectionMethod($response, 'content'))->invoke($response);
    $payload = json_decode($content[0], true, flags: JSON_THROW_ON_ERROR);

    expect($payload['date'])->toBe('2026-08-07')
        ->and($payload['completed'])->toBeTrue()
        ->and($payload['completed_at_label'])->toBe('21:30');
});
