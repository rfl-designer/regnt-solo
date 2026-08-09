<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use App\Services\ShapingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/**
 * A página de shaping e as duas prateleiras que a alimentam (issue #148).
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('the page opens from an idea with the five sections', function () {
    $draft = Activity::factory()->draft()->create(['title' => 'Fila de puxar']);

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->assertSuccessful()
        ->assertSee('Fila de puxar')
        ->assertSee('Dor')
        ->assertSee('Apetite')
        ->assertSee('Esboço')
        ->assertSee('Rabbit holes')
        ->assertSee('No-gos');
});

test('the page refuses to open anything that is not an idea', function () {
    $epic = Activity::factory()->epic()->create();

    // Pela rota, o "não" chega como 404 — shaping é da Ideia, e um Épico já
    // apostado não volta para a mesa.
    $this->get(route('shaping', $epic))->assertNotFound();
});

test('the shaping component itself refuses a non-idea', function () {
    $epic = Activity::factory()->epic()->create();

    expect(fn () => Livewire::test('pages::shaping', ['draft' => $epic->id]))
        ->toThrow(ModelNotFoundException::class);
});

test('every section autosaves, including the three new fields', function () {
    $draft = Activity::factory()->draft()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->set('dor', 'Refaço isso à mão toda semana.')
        ->set('esboco', 'Uma fila que se ordena sozinha.')
        ->set('rabbitHoles', 'Ordenação com empates.')
        ->set('noGos', 'Não mexe no Backlog.');

    expect($draft->refresh())
        ->description->toBe('Refaço isso à mão toda semana.')
        ->spec->toBe('Uma fila que se ordena sozinha.')
        ->rabbit_holes->toBe('Ordenação com empates.')
        ->no_gos->toBe('Não mexe no Backlog.');
});

test('an appetite chip persists the budget in calendar days', function () {
    $draft = Activity::factory()->draft()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('chooseAppetite', 14)
        ->assertSet('appetiteDays', 14)
        ->assertSet('customAppetite', false);

    expect($draft->refresh()->appetite_days)->toBe(14);
});

test('"outro" takes a free value and keeps it selected on the way back', function () {
    $draft = Activity::factory()->draft()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('chooseCustomAppetite')
        ->set('customAppetiteDays', 45)
        ->assertSet('appetiteDays', 45);

    expect($draft->refresh()->appetite_days)->toBe(45);

    // Reabrir a página reconhece 45 como valor livre, não como chip.
    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->assertSet('customAppetite', true)
        ->assertSet('customAppetiteDays', 45);
});

test('"outro" keeps a free value inside the column instead of dying on autosave', function () {
    $draft = Activity::factory()->draft()->create();

    // O valor é livre, mas a coluna tem largura: um dígito a mais no campo
    // não pode virar um write fora de faixa num autosave que ninguém pediu.
    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('chooseCustomAppetite')
        ->set('customAppetiteDays', 999999)
        ->assertSet('appetiteDays', ShapingService::MAX_APPETITE_DAYS)
        ->set('customAppetiteDays', 0)
        ->assertSet('appetiteDays', null);

    expect($draft->refresh()->appetite_days)->toBeNull();
});

test('a note captured in the Ideias editor arrives as Dor without being flattened', function () {
    // O modal de Ideias escreve `description` com <flux:editor>, isto é, HTML.
    // A mesma coluna é a Dor: a página tem de carregá-la como está, e o pitch
    // é quem a achata para markdown.
    $draft = Activity::factory()->draft()->create([
        'description' => '<p>Refaço isso <strong>à mão</strong> toda semana.</p>',
    ]);

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->assertSet('dor', '<p>Refaço isso <strong>à mão</strong> toda semana.</p>')
        ->call('chooseAppetite', 7);

    expect($draft->refresh()->description)->toBe('<p>Refaço isso <strong>à mão</strong> toda semana.</p>');
    expect(app(ShapingService::class)->pitch($draft->refresh()))
        ->toContain('Refaço isso à mão toda semana.')
        ->not->toContain('<strong>');
});

test('the history aside shows "ainda sem histórico" with fewer than three validated specs', function () {
    $draft = Activity::factory()->draft()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->assertSee('Ainda sem histórico')
        ->assertSee('Seu histórico');
});

test('promoting without dor, apetite, esboço or projeto is refused with a clear message', function () {
    $draft = Activity::factory()->draft()->create(['description' => null, 'spec' => null]);

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('promote')
        ->assertNoRedirect()
        ->assertSee('Falta para promover: Dor, Apetite, Esboço, Projeto.');

    expect($draft->refresh()->type)->toBe(ActivityType::Draft);
});

test('promoting a shaped idea without a project is still refused', function () {
    $draft = Activity::factory()->draft()->shaped()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('promote')
        ->assertNoRedirect();

    expect($draft->refresh()->type)->toBe(ActivityType::Draft);
});

test('with everything filled the same record becomes an epic in backlog', function () {
    $project = Project::factory()->create();
    $draft = Activity::factory()->draft()->create(['title' => 'Fila de puxar']);

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->set('dor', 'Não sei o que pegar depois de terminar.')
        ->call('chooseAppetite', 7)
        ->set('esboco', 'Pronto se auto-ordena.')
        ->set('projectId', $project->id)
        ->call('promote')
        ->assertRedirect(route('kanban'));

    expect($draft->refresh())
        ->type->toBe(ActivityType::Epic)
        ->status->toBe(ActivityStatus::Backlog)
        ->project_id->toBe($project->id)
        ->appetite_days->toBe(7);
});

test('"talvez mais adiante" keeps the partial: leaving writes nothing away', function () {
    $draft = Activity::factory()->draft()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->set('dor', 'Metade de uma dor')
        ->set('rabbitHoles', 'Um buraco')
        // A saída é um link para Ideias — não há ação a chamar, e é esse o ponto.
        ->assertSee('Talvez mais adiante');

    expect($draft->refresh())
        ->description->toBe('Metade de uma dor')
        ->rabbit_holes->toBe('Um buraco')
        ->type->toBe(ActivityType::Draft);
});

test('the footer counts the progress out of five', function () {
    $draft = Activity::factory()->draft()->shaped()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->assertSee('3/5')
        ->assertSee('Falta para promover: Projeto.');
});

test('the epic modal copies the very same pitch as the shaping page', function () {
    $project = Project::factory()->create();
    $draft = Activity::factory()->draft()->shaped()->create(['title' => 'Painel de políticas']);

    $pitch = app(ShapingService::class)->pitch($draft);

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->set('projectId', $project->id)
        ->call('promote');

    Livewire::test('feature-modal')
        ->call('open', $draft->id)
        ->assertSee('Copiar pitch')
        ->assertSet('pitch', $pitch);
});

test('ideas splits the shelves into "Com forma" and "Brutas"', function () {
    Activity::factory()->draft()->create(['title' => 'Ideia bruta', 'description' => null]);
    Activity::factory()->draft()->shaped()->create(['title' => 'Ideia com forma']);

    Livewire::test('pages::ideas')
        ->assertSuccessful()
        ->assertSee('Com forma')
        ->assertSee('Brutas')
        ->assertSee('Ideia com forma')
        ->assertSee('Ideia bruta')
        ->assertSee('Retomar shaping')
        ->assertSee('3/5');
});

test('a raw idea offers to start shaping and a shaped one to resume it', function () {
    $raw = Activity::factory()->draft()->create(['title' => 'Bruta', 'description' => null]);
    $shaped = Activity::factory()->draft()->shaped()->create(['title' => 'Com forma']);

    Livewire::test('pages::ideas')
        ->assertSee('Dar forma')
        ->assertSeeHtml(route('shaping', $raw, absolute: false))
        ->assertSeeHtml(route('shaping', $shaped, absolute: false));
});
