<?php

use App\Enums\HillPosition;
use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

/**
 * O hill da spec (issue #149): binário, manual e marcado no modal do Épico.
 *
 * Como o gerador do update lê a posição — e por que só em nível spec — é
 * assunto do ClientUpdateServiceTest.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('o modal do Épico abre com a posição atual da colina', function () {
    $epic = Activity::factory()->epic()->create(['hill_position' => HillPosition::Uphill]);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->assertSet('hillPosition', 'uphill')
        ->assertSeeHtml('data-test="hill-toggle"');
});

test('marcar a colina grava na hora', function () {
    $epic = Activity::factory()->epic()->create();

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->call('setHill', 'downhill')
        ->assertSet('hillPosition', 'downhill');

    expect($epic->fresh()->hill_position)->toBe(HillPosition::Downhill);
});

test('clicar na posição já marcada a limpa — não saber é um estado', function () {
    $epic = Activity::factory()->epic()->create(['hill_position' => HillPosition::Downhill]);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->call('setHill', 'downhill')
        ->assertSet('hillPosition', null);

    expect($epic->fresh()->hill_position)->toBeNull();
});

test('um valor forjado não vira posição', function () {
    $epic = Activity::factory()->epic()->create();

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->call('setHill', 'summit');

    expect($epic->fresh()->hill_position)->toBeNull();
});
