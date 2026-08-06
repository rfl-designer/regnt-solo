<?php

use App\Mail\StakeholderAccessLink;
use App\Models\Client;
use App\Models\Project;
use App\Models\Stakeholder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->project = Project::factory()->create(['name' => 'Projeto Gamma']);
});

it('modal opens correctly via dispatch', function () {
    $component = Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->assertSet('projectId', $this->project->id);
});

it('lists stakeholders of the project', function () {
    $stakeholder1 = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'Alice Johnson',
        'email' => 'alice@example.com',
    ]);

    $stakeholder2 = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'Bob Smith',
        'email' => 'bob@example.com',
    ]);

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->assertSee('Alice Johnson')
        ->assertSee('alice@example.com')
        ->assertSee('Bob Smith')
        ->assertSee('bob@example.com');
});

it('can add stakeholder with valid data', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'Charlie Brown')
        ->set('email', 'charlie@example.com')
        ->call('addStakeholder')
        ->assertHasNoErrors();

    expect(Stakeholder::where('email', 'charlie@example.com')->exists())->toBeTrue();
});

it('validates name is required', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', '')
        ->set('email', 'valid@example.com')
        ->call('addStakeholder')
        ->assertHasErrors(['name' => 'required']);
});

it('validates email is required', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'Valid Name')
        ->set('email', '')
        ->call('addStakeholder')
        ->assertHasErrors(['email' => 'required']);
});

it('validates email format', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'Valid Name')
        ->set('email', 'invalid-email')
        ->call('addStakeholder')
        ->assertHasErrors(['email' => 'email']);
});

it('allows duplicate email per project at model level', function () {
    // Note: Email uniqueness validation is at application level in the Livewire component
    // but at the model level it is allowed (no unique constraint in DB)
    Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'email' => 'duplicate@example.com',
    ]);

    $stakeholder2 = Stakeholder::create([
        'project_id' => $this->project->id,
        'name' => 'Another Person',
        'email' => 'duplicate@example.com',
    ]);

    expect($stakeholder2)->toBeInstanceOf(Stakeholder::class);
    expect(Stakeholder::where('email', 'duplicate@example.com')->count())->toBe(2);
});

it('sends email when adding stakeholder', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'David Lee')
        ->set('email', 'david@example.com')
        ->call('addStakeholder');

    Mail::assertSent(StakeholderAccessLink::class, function ($mail) {
        return $mail->hasTo('david@example.com');
    });
});

it('can resend access link', function () {
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'email' => 'resend@example.com',
    ]);

    Mail::fake();

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->call('resendEmail', $stakeholder->id);

    Mail::assertSent(StakeholderAccessLink::class, function ($mail) use ($stakeholder) {
        return $mail->hasTo($stakeholder->email);
    });
});

it('can remove stakeholder', function () {
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'To Be Removed',
        'email' => 'remove@example.com',
    ]);

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->call('removeStakeholder', $stakeholder->id)
        ->assertHasNoErrors();

    expect(Stakeholder::find($stakeholder->id))->toBeNull();
});

it('shows empty state when no stakeholders', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->assertSee('Nenhum stakeholder adicionado');
});

it('does not show empty state when stakeholders exist', function () {
    Stakeholder::factory()->create([
        'project_id' => $this->project->id,
    ]);

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->assertDontSee('Nenhum stakeholder adicionado');
});

it('clears form after adding stakeholder', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'Eve Wilson')
        ->set('email', 'eve@example.com')
        ->call('addStakeholder')
        ->assertSet('name', '')
        ->assertSet('email', '');
});

it('successfully adds stakeholder to database', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'Frank Miller')
        ->set('email', 'frank@example.com')
        ->call('addStakeholder')
        ->assertHasNoErrors();

    expect(Stakeholder::where('email', 'frank@example.com')->exists())->toBeTrue();
});

it('shows last accessed date if stakeholder has accessed', function () {
    $stakeholder = Stakeholder::factory()->recentlyAccessed()->create([
        'project_id' => $this->project->id,
        'name' => 'Recent Visitor',
    ]);

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->assertSee('Recent Visitor')
        ->assertSee('Acessou');
});

it('shows never accessed indicator if stakeholder has never accessed', function () {
    $stakeholder = Stakeholder::factory()->neverAccessed()->create([
        'project_id' => $this->project->id,
        'name' => 'Never Visited',
    ]);

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->assertSee('Never Visited')
        ->assertSee('Nunca acessou');
});

it('only lists stakeholders from the specific project', function () {
    $ownStakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'Own Stakeholder',
    ]);

    $otherProject = Project::factory()->create();
    $otherStakeholder = Stakeholder::factory()->create([
        'project_id' => $otherProject->id,
        'name' => 'Other Stakeholder',
    ]);

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->assertSee('Own Stakeholder')
        ->assertDontSee('Other Stakeholder');
});

it('can add a stakeholder with a client link', function () {
    $client = Client::factory()->create(['name' => 'Acme Corp']);

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'Grace Hopper')
        ->set('email', 'grace@example.com')
        ->set('clientId', $client->id)
        ->call('addStakeholder')
        ->assertHasNoErrors();

    expect(Stakeholder::where('email', 'grace@example.com')->first())
        ->client_id->toBe($client->id);
});

it('can add a stakeholder without a client link', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'Henry Ford')
        ->set('email', 'henry@example.com')
        ->call('addStakeholder')
        ->assertHasNoErrors();

    expect(Stakeholder::where('email', 'henry@example.com')->first())
        ->client_id->toBeNull();
});

it('validates the client link exists', function () {
    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set('name', 'Ida Tarbell')
        ->set('email', 'ida@example.com')
        ->set('clientId', 999999)
        ->call('addStakeholder')
        ->assertHasErrors(['clientId' => 'exists']);
});

it('can update the client link of an existing stakeholder without touching its access token', function () {
    $client = Client::factory()->create();
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'client_id' => null,
    ]);
    $originalToken = $stakeholder->access_token;

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set("stakeholderClientIds.{$stakeholder->id}", $client->id)
        ->call('updateStakeholderClient', $stakeholder->id)
        ->assertHasNoErrors();

    expect($stakeholder->fresh())
        ->client_id->toBe($client->id)
        ->access_token->toBe($originalToken);
});

it('can clear the client link of an existing stakeholder', function () {
    $client = Client::factory()->create();
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'client_id' => $client->id,
    ]);

    Livewire::test('project-stakeholders-modal')
        ->dispatch('open-stakeholders-modal', projectId: $this->project->id)
        ->set("stakeholderClientIds.{$stakeholder->id}", '')
        ->call('updateStakeholderClient', $stakeholder->id)
        ->assertHasNoErrors();

    expect($stakeholder->fresh())->client_id->toBeNull();
});
