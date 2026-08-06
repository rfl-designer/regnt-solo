<?php

use App\Enums\ClientChannel;
use App\Models\Client;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

describe('Clients Page Access', function (): void {
    test('clients route requires authentication', function (): void {
        auth()->logout();

        $this->get(route('clients'))
            ->assertRedirect(route('login'));
    });

    test('clients page renders correctly for authenticated users', function (): void {
        $this->get(route('clients'))
            ->assertOk();
    });

    test('clients component renders successfully', function (): void {
        Livewire::test('pages::clients')
            ->assertSuccessful()
            ->assertSee('Clientes');
    });
});

describe('Clients List', function (): void {
    test('shows empty state when no clients exist', function (): void {
        Livewire::test('pages::clients')
            ->assertSee('Nenhum cliente');
    });

    test('lists active clients', function (): void {
        Client::factory()->create(['name' => 'Acme Corp']);
        Client::factory()->create(['name' => 'Globex Inc']);

        Livewire::test('pages::clients')
            ->assertSee('Acme Corp')
            ->assertSee('Globex Inc');
    });

    test('archived tab only shows archived clients', function (): void {
        Client::factory()->create(['name' => 'Ativo Ltda']);
        Client::factory()->archived()->create(['name' => 'Arquivado Ltda']);

        Livewire::test('pages::clients')
            ->set('tab', 'archived')
            ->assertSee('Arquivado Ltda')
            ->assertDontSee('Ativo Ltda');
    });

    test('switching back to active tab re-renders the list', function (): void {
        Client::factory()->create(['name' => 'Ativo Ltda']);
        Client::factory()->archived()->create(['name' => 'Arquivado Ltda']);

        Livewire::test('pages::clients')
            ->set('tab', 'archived')
            ->assertSee('Arquivado Ltda')
            ->assertDontSee('Ativo Ltda')
            ->set('tab', 'active')
            ->assertSee('Ativo Ltda')
            ->assertDontSee('Arquivado Ltda');
    });
});

describe('Clients Form', function (): void {
    test('can open the create form', function (): void {
        Livewire::test('pages::clients')
            ->call('openForm')
            ->assertSet('showForm', true)
            ->assertSet('editingClientId', null);
    });

    test('can create a new client with valid data', function (): void {
        Livewire::test('pages::clients')
            ->call('openForm')
            ->set('name', 'Novo Cliente')
            ->set('color', '#ff0000')
            ->set('updateDay', 3)
            ->set('updateTime', '10:00')
            ->set('channel', 'whatsapp')
            ->set('responseAgreement', 'Responder em 24h')
            ->call('saveClient')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        expect(Client::count())->toBe(1);

        $client = Client::first();
        expect($client)
            ->name->toBe('Novo Cliente')
            ->slug->toBe('novo-cliente')
            ->color->toBe('#ff0000')
            ->update_day->toBe(3)
            ->channel->toBe(ClientChannel::Whatsapp)
            ->response_agreement->toBe('Responder em 24h');
    });

    test('validates name is required', function (): void {
        Livewire::test('pages::clients')
            ->call('openForm')
            ->set('name', '')
            ->call('saveClient')
            ->assertHasErrors(['name' => 'required']);
    });

    test('rejects an invalid hex color', function (): void {
        Livewire::test('pages::clients')
            ->call('openForm')
            ->set('name', 'Cliente Cor Inválida')
            ->set('color', 'not-a-color')
            ->call('saveClient')
            ->assertHasErrors(['color' => 'regex']);

        expect(Client::count())->toBe(0);
    });

    test('requires an update day between 1 and 7', function (): void {
        Livewire::test('pages::clients')
            ->call('openForm')
            ->set('name', 'Cliente Sem Dia')
            ->set('updateDay', 0)
            ->call('saveClient')
            ->assertHasErrors(['updateDay' => 'between']);

        Livewire::test('pages::clients')
            ->call('openForm')
            ->set('name', 'Cliente Sem Dia')
            ->set('updateDay', 8)
            ->call('saveClient')
            ->assertHasErrors(['updateDay' => 'between']);
    });

    test('prevents duplicate slugs', function (): void {
        Client::factory()->create(['name' => 'Cliente Teste', 'slug' => 'cliente-teste']);

        Livewire::test('pages::clients')
            ->call('openForm')
            ->set('name', 'Cliente Teste')
            ->call('saveClient')
            ->assertHasErrors('name');

        expect(Client::count())->toBe(1);
    });

    test('can open edit form with existing data', function (): void {
        $client = Client::factory()->create([
            'name' => 'Cliente Existente',
            'channel' => ClientChannel::Slack,
        ]);

        Livewire::test('pages::clients')
            ->call('openForm', $client->id)
            ->assertSet('editingClientId', $client->id)
            ->assertSet('name', 'Cliente Existente')
            ->assertSet('channel', 'slack');
    });

    test('can update an existing client', function (): void {
        $client = Client::factory()->create(['name' => 'Nome Antigo']);

        Livewire::test('pages::clients')
            ->call('openForm', $client->id)
            ->set('name', 'Nome Novo')
            ->call('saveClient');

        expect($client->fresh()->name)->toBe('Nome Novo');
    });
});

describe('Clients Archive & Delete', function (): void {
    test('can archive an active client', function (): void {
        $client = Client::factory()->create(['is_active' => true]);

        Livewire::test('pages::clients')
            ->call('toggleActive', $client->id);

        expect($client->fresh()->is_active)->toBeFalse();
    });

    test('can reactivate an archived client', function (): void {
        $client = Client::factory()->archived()->create();

        Livewire::test('pages::clients')
            ->call('toggleActive', $client->id);

        expect($client->fresh()->is_active)->toBeTrue();
    });

    test('can delete a client with confirmation', function (): void {
        $client = Client::factory()->create();

        Livewire::test('pages::clients')
            ->call('confirmDelete', $client->id)
            ->assertSet('showDeleteModal', true)
            ->call('deleteClient')
            ->assertSet('showDeleteModal', false);

        expect(Client::find($client->id))->toBeNull();
    });
});
