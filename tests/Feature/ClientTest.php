<?php

use App\Enums\ClientChannel;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Project;

test('client can be created with all fields', function () {
    $client = Client::factory()->create([
        'name' => 'Acme Corp',
        'slug' => 'acme-corp',
        'color' => '#ff0000',
        'update_day' => 2,
        'update_time' => '14:30',
        'channel' => ClientChannel::Whatsapp,
        'response_agreement' => 'Responder em até 24h úteis',
        'notes' => 'Cliente estratégico',
        'is_active' => true,
    ]);

    expect($client)
        ->name->toBe('Acme Corp')
        ->slug->toBe('acme-corp')
        ->color->toBe('#ff0000')
        ->update_day->toBe(2)
        ->channel->toBe(ClientChannel::Whatsapp)
        ->response_agreement->toBe('Responder em até 24h úteis')
        ->notes->toBe('Cliente estratégico')
        ->is_active->toBeTrue();
});

test('client has many projects', function () {
    $client = Client::factory()->create();
    Project::factory()->count(2)->create(['client_id' => $client->id]);

    expect($client->projects)->toHaveCount(2);
});

test('client has many activities directly linked', function () {
    $client = Client::factory()->create();
    Activity::factory()->count(2)->create(['client_id' => $client->id, 'project_id' => null]);

    expect($client->activities)->toHaveCount(2);
});

test('active scope only returns non-archived clients', function () {
    Client::factory()->create(['is_active' => true]);
    Client::factory()->archived()->create();

    expect(Client::active()->count())->toBe(1);
    expect(Client::archived()->count())->toBe(1);
});

test('update day label returns pt-br weekday name', function () {
    $client = Client::factory()->create(['update_day' => 1]);

    expect($client->updateDayLabel())->toBe('Segunda-feira');
});

test('update day label returns null when no update day set', function () {
    $client = Client::factory()->create(['update_day' => null]);

    expect($client->updateDayLabel())->toBeNull();
});

test('deleting a client nullifies the client_id on projects and activities', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->create(['client_id' => $client->id]);
    $activity = Activity::factory()->create(['client_id' => $client->id, 'project_id' => null]);

    $client->delete();

    expect($project->fresh()->client_id)->toBeNull();
    expect($activity->fresh()->client_id)->toBeNull();
});
