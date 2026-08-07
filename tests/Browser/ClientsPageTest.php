<?php

use App\Models\Client;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('lists active clients on the clients page', function (): void {
    Client::factory()->create(['name' => 'Acme Corp', 'is_active' => true]);
    Client::factory()->create(['name' => 'Globex Inc', 'is_active' => true]);

    visit('/clients')
        ->assertNoJavaScriptErrors()
        ->assertSee('Clientes')
        ->assertSee('Acme Corp')
        ->assertSee('Globex Inc');
});

test('shows the empty state when there are no clients', function (): void {
    visit('/clients')
        ->assertSee('Nenhum cliente')
        ->assertSee('Cadastre clientes para organizar projetos e tasks.');
});

test('creates a new client through the form', function (): void {
    $page = visit('/clients');

    $page->assertSee('Nenhum cliente')
        ->click('Novo Cliente')
        ->waitForText('Novo Cliente')
        ->fill('name', 'Iniciativa Umbrella')
        ->click('Criar')
        ->waitForText('Iniciativa Umbrella')
        ->assertSee('Iniciativa Umbrella')
        ->assertNoJavaScriptErrors();

    expect(Client::where('name', 'Iniciativa Umbrella')->exists())->toBeTrue();
});

test('archives a client and finds it on the archived tab', function (): void {
    Client::factory()->create(['name' => 'Acme Corp', 'is_active' => true]);

    $page = visit('/clients');

    $page->assertSee('Acme Corp')
        ->click('[title="Arquivar"] >> visible=true')
        ->waitForText('Cadastre clientes para organizar projetos e tasks.')
        ->assertDontSee('Acme Corp');

    expect(Client::where('name', 'Acme Corp')->first()->is_active)->toBeFalse();

    $page->click('Arquivados')
        ->waitForText('Acme Corp')
        ->assertSee('Acme Corp')
        ->assertNoJavaScriptErrors();
});

test('reactivates an archived client', function (): void {
    Client::factory()->create(['name' => 'Acme Corp', 'is_active' => false]);

    $page = visit('/clients?tab=archived');

    $page->assertSee('Acme Corp')
        ->click('[title="Reativar"] >> visible=true')
        ->waitForText('Nenhum cliente arquivado.')
        ->assertNoJavaScriptErrors();

    expect(Client::where('name', 'Acme Corp')->first()->is_active)->toBeTrue();
});
