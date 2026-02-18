<?php

use App\Models\User;

test('cria usuário com credenciais do config', function () {
    config(['solo.user_email' => 'test@soloboard.local']);
    config(['solo.user_password' => 'secret123']);

    $this->artisan('soloboard:create-user')
        ->expectsOutput('Usuário criado com sucesso!')
        ->assertSuccessful();

    expect(User::where('email', 'test@soloboard.local')->exists())->toBeTrue();
});

test('cria usuário com opções customizadas', function () {
    $this->artisan('soloboard:create-user', [
        '--name' => 'João Silva',
        '--email' => 'joao@exemplo.com',
        '--password' => 'minhasenha',
    ])
        ->expectsOutput('Usuário criado com sucesso!')
        ->assertSuccessful();

    $user = User::where('email', 'joao@exemplo.com')->first();

    expect($user)->not->toBeNull();
    expect($user->name)->toBe('João Silva');
});

test('não recria usuário existente sem force', function () {
    User::factory()->create(['email' => 'existente@soloboard.local']);

    $this->artisan('soloboard:create-user', [
        '--email' => 'existente@soloboard.local',
    ])
        ->expectsOutputToContain('já existe')
        ->assertSuccessful();

    expect(User::where('email', 'existente@soloboard.local')->count())->toBe(1);
});

test('recria usuário existente com force', function () {
    User::factory()->create([
        'email' => 'antigo@soloboard.local',
        'name' => 'Usuário Antigo',
    ]);

    $this->artisan('soloboard:create-user', [
        '--email' => 'antigo@soloboard.local',
        '--name' => 'Usuário Novo',
        '--force' => true,
    ])
        ->expectsOutput('Usuário existente removido.')
        ->expectsOutput('Usuário criado com sucesso!')
        ->assertSuccessful();

    $user = User::where('email', 'antigo@soloboard.local')->first();

    expect($user->name)->toBe('Usuário Novo');
});

test('usa nome padrão quando não especificado', function () {
    $this->artisan('soloboard:create-user', [
        '--email' => 'padrao@soloboard.local',
    ])
        ->assertSuccessful();

    $user = User::where('email', 'padrao@soloboard.local')->first();

    expect($user->name)->toBe('Solo Developer');
});
