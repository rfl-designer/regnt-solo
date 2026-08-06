<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Stakeholder;

beforeEach(function () {
    $this->project = Project::factory()->create();
});

it('can create stakeholder with unique token', function () {
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'João Silva',
        'email' => 'joao@example.com',
    ]);

    expect($stakeholder->access_token)
        ->not->toBeNull()
        ->toBeString();
});

it('generates token automatically on boot if not provided', function () {
    $stakeholder = Stakeholder::create([
        'project_id' => $this->project->id,
        'name' => 'Maria Santos',
        'email' => 'maria@example.com',
    ]);

    expect($stakeholder->access_token)
        ->not->toBeNull()
        ->toBeString()
        ->toHaveLength(36); // UUID v4 length
});

it('project has many stakeholders relationship', function () {
    Stakeholder::factory()->count(3)->create([
        'project_id' => $this->project->id,
    ]);

    expect($this->project->stakeholders)
        ->toHaveCount(3);
});

it('stakeholder belongs to project relationship', function () {
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
    ]);

    expect($stakeholder->project)
        ->toBeInstanceOf(Project::class)
        ->id->toBe($this->project->id);
});

it('public url accessor generates correct url', function () {
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
    ]);

    expect($stakeholder->public_url)
        ->toContain("/projects/shared/{$stakeholder->access_token}")
        ->toStartWith('http');
});

it('deletes stakeholders when project is deleted', function () {
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $stakeholderId = $stakeholder->id;

    $this->project->delete();

    expect(Stakeholder::find($stakeholderId))->toBeNull();
});

it('allows creating stakeholder with duplicate email in same project', function () {
    // Note: Validation is at application level, not database level
    Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'email' => 'duplicado@example.com',
    ]);

    $stakeholder2 = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'email' => 'duplicado@example.com',
    ]);

    expect($stakeholder2->email)->toBe('duplicado@example.com');
    expect(Stakeholder::where('email', 'duplicado@example.com')->count())->toBe(2);
});

it('same email can exist in different projects', function () {
    $anotherProject = Project::factory()->create();

    $stakeholder1 = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'email' => 'shared@example.com',
    ]);

    $stakeholder2 = Stakeholder::factory()->create([
        'project_id' => $anotherProject->id,
        'email' => 'shared@example.com',
    ]);

    expect($stakeholder1->email)->toBe($stakeholder2->email);
    expect($stakeholder1->id)->not->toBe($stakeholder2->id);
});

it('accepts an optional client link without altering the portal mechanism', function () {
    $client = Client::factory()->create();

    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'client_id' => $client->id,
    ]);

    expect($stakeholder->client)
        ->toBeInstanceOf(Client::class)
        ->id->toBe($client->id);

    expect($stakeholder->access_token)->not->toBeNull();
    expect($stakeholder->public_url)->toContain("/projects/shared/{$stakeholder->access_token}");
});

it('stakeholder client link is nullable', function () {
    $stakeholder = Stakeholder::factory()->create([
        'project_id' => $this->project->id,
        'client_id' => null,
    ]);

    expect($stakeholder->client_id)->toBeNull();
    expect($stakeholder->client)->toBeNull();
});
