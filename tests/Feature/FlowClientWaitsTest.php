<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * The "esperas por cliente" ranking on the Fluxo page (issue #146). The
 * page ranks nothing itself — it renders FlowMetricsService::clientWaitRanking(),
 * which is covered in SpecFlowEfficiencyTest.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the page ranks the clients by how long they kept the board waiting', function () {
    $slow = Client::factory()->create(['name' => 'Cliente Lento']);
    $fast = Client::factory()->create(['name' => 'Cliente Rápido']);

    withSpecHistory(Activity::factory()->epic()->todo()->create(['client_id' => $slow->id]), [
        [ActivityStatus::AwaitingApproval, '2026-08-01 12:00'],
        [ActivityStatus::Todo, '2026-08-06 12:00'],
    ]);
    withSpecHistory(Activity::factory()->issue()->todo()->create(['client_id' => $slow->id]), [
        [ActivityStatus::AwaitingValidation, '2026-08-05 12:00'],
        [ActivityStatus::Todo, '2026-08-07 12:00'],
    ]);
    withSpecHistory(Activity::factory()->issue()->todo()->create(['client_id' => $fast->id]), [
        [ActivityStatus::AwaitingApproval, '2026-08-06 12:00'],
        [ActivityStatus::Todo, '2026-08-07 12:00'],
    ]);

    Livewire::test('pages::flow')
        ->assertSee('Esperas por cliente')
        ->assertSeeInOrder(['Cliente Lento', 'Cliente Rápido'])
        // 5 + 2 dias para o lento, em 2 itens; 1 dia para o rápido.
        ->assertSee('7,0 dias')
        ->assertSee('2 itens')
        ->assertSee('1,0 dias')
        ->assertSee('1 item');
});

test('the internal wait never lands on a client tab', function () {
    $client = Client::factory()->create(['name' => 'Cliente Único']);

    withSpecHistory(Activity::factory()->issue()->doing()->create(['client_id' => $client->id]), [
        [ActivityStatus::Waiting, '2026-08-01 12:00'],
        [ActivityStatus::Doing, '2026-08-06 12:00'],
    ]);

    Livewire::test('pages::flow')
        ->assertDontSee('Cliente Único')
        ->assertSee('Nenhuma espera de cliente nos últimos 30 dias.');
});
