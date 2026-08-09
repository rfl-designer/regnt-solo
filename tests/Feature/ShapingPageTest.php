<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Exceptions\ShapingIncompleteException;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use App\Services\ShapingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

test('a second tab does not restore the fields the first one wrote', function () {
    $draft = Activity::factory()->draft()->create(['description' => null, 'spec' => null]);

    // Duas abas abertas na mesma Ideia: ambas hidratam com tudo vazio.
    $tabA = Livewire::test('pages::shaping', ['draft' => $draft->id]);
    $tabB = Livewire::test('pages::shaping', ['draft' => $draft->id]);

    $tabA->set('dor', 'A dor que a aba A escreveu');

    // A aba B nunca soube da dor. Gravando o snapshot inteiro, este blur
    // devolveria `description` para null.
    $tabB->set('noGos', 'O no-go que a aba B escreveu');

    expect($draft->refresh())
        ->description->toBe('A dor que a aba A escreveu')
        ->no_gos->toBe('O no-go que a aba B escreveu');
});

test('an out-of-order autosave only loses to another edit of the same field', function () {
    $draft = Activity::factory()->draft()->create();

    $stale = Livewire::test('pages::shaping', ['draft' => $draft->id]);

    // Um request mais novo grava apetite e esboço...
    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('chooseAppetite', 14)
        ->set('esboco', 'O esboço mais novo');

    // ...e o request velho chega depois, carregando um snapshot sem nenhum
    // dos dois. Só a coluna que ele de fato editou pode mudar.
    $stale->set('rabbitHoles', 'Um buraco atrasado');

    expect($draft->refresh())
        ->appetite_days->toBe(14)
        ->spec->toBe('O esboço mais novo')
        ->rabbit_holes->toBe('Um buraco atrasado');
});

test('the write target cannot be moved onto another kind of activity', function () {
    $draft = Activity::factory()->draft()->create();
    $epic = Activity::factory()->epic()->create([
        'description' => 'A dor do Épico',
        'spec' => 'O esboço do Épico',
    ]);

    // O id é #[Locked]: o payload adulterado é recusado pelo próprio Livewire.
    expect(fn () => Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->set('draftId', $epic->id)
    )->toThrow(CannotUpdateLockedPropertyException::class);

    expect($epic->refresh())
        ->description->toBe('A dor do Épico')
        ->spec->toBe('O esboço do Épico')
        ->type->toBe(ActivityType::Epic);
});

test('a forged chip value is refused instead of becoming an apetite', function () {
    $draft = Activity::factory()->draft()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('chooseAppetite', 0)
        ->assertSet('appetiteDays', null)
        ->call('chooseAppetite', -5)
        ->assertSet('appetiteDays', null)
        ->call('chooseAppetite', 999)
        ->assertSet('appetiteDays', null);

    expect($draft->refresh()->appetite_days)->toBeNull();
});

test('a zero apetite does not count as a filled section', function () {
    $draft = Activity::factory()->draft()->shaped()->create();
    $draft->forceFill(['appetite_days' => 0])->save();

    // Sem isto, um apetite forjado passaria pelo portão de promoção.
    expect(app(ShapingService::class)->missingForPromotion($draft))
        ->toContain('Apetite');
});

test('a project that is not active is no project at all', function () {
    $draft = Activity::factory()->draft()->shaped()->create();

    foreach (['archived', 'done', 'paused'] as $state) {
        $project = Project::factory()->{$state}()->create();

        expect(app(ShapingService::class)->missingForPromotion($draft, $project->id))
            ->toContain('Projeto');

        expect(fn () => app(ShapingService::class)->promote($draft, $project->id))
            ->toThrow(ShapingIncompleteException::class);
    }

    expect($draft->refresh()->type)->toBe(ActivityType::Draft);
});

test('the selector only offers projects a bet can be placed in', function () {
    $draft = Activity::factory()->draft()->create();
    Project::factory()->create(['name' => 'Projeto Vivo']);
    Project::factory()->archived()->create(['name' => 'Projeto Arquivado']);

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->assertSee('Projeto Vivo')
        ->assertDontSee('Projeto Arquivado');
});

test('a forged project id never reaches the database', function () {
    $draft = Activity::factory()->draft()->create();
    $archived = Project::factory()->archived()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->set('projectId', 999999)
        ->assertSet('projectId', null)
        ->set('projectId', $archived->id)
        ->assertSet('projectId', null);

    expect($draft->refresh()->project_id)->toBeNull();
});

test('the chosen chip says it is chosen, not just looks it', function () {
    $draft = Activity::factory()->draft()->create();

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('chooseAppetite', 14)
        ->assertSeeHtml('data-test="appetite-chip-14" aria-pressed="true"')
        ->assertSeeHtml('data-test="appetite-chip-7" aria-pressed="false"')
        ->assertSeeHtml('data-test="appetite-chip-other" aria-pressed="false"');
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

test('copying the pitch on the shaping page uses what is on screen, not what was saved', function () {
    $draft = Activity::factory()->draft()->shaped()->create(['title' => 'Fila de puxar']);

    // O componente carrega o estado salvo e o usuário edita sem que o blur
    // tenha chegado ao banco: a cópia tem de trazer a edição mesmo assim.
    $component = Livewire::test('pages::shaping', ['draft' => $draft->id]);
    $component->set('dor', 'A dor recém-editada');

    $component->call('copyPitch')
        ->assertDispatched('copy-to-clipboard', function (string $event, array $params): bool {
            return str_contains($params['markdown'], 'A dor recém-editada');
        });
});

test('the shaping pitch copies the apetite chosen in this very request', function () {
    $draft = Activity::factory()->draft()->create(['title' => 'Fila de puxar']);

    Livewire::test('pages::shaping', ['draft' => $draft->id])
        ->call('chooseAppetite', 21)
        ->call('copyPitch')
        ->assertDispatched('copy-to-clipboard', function (string $event, array $params): bool {
            return str_contains($params['markdown'], '21 dias');
        });
});

test('the epic modal copies the title and spec still sitting in the form', function () {
    $epic = Activity::factory()->epic()->create([
        'title' => 'Título salvo',
        'spec' => 'Esboço salvo',
    ]);

    Livewire::test('feature-modal')
        ->call('open', $epic->id)
        ->set('title', 'Título ainda não salvo')
        ->set('spec', 'Esboço ainda não salvo')
        ->call('copyPitch')
        ->assertDispatched('copy-to-clipboard', function (string $event, array $params): bool {
            return str_contains($params['markdown'], 'Título ainda não salvo')
                && str_contains($params['markdown'], 'Esboço ainda não salvo')
                && ! str_contains($params['markdown'], 'Título salvo');
        });

    // Copiar não é salvar.
    expect($epic->refresh())
        ->title->toBe('Título salvo')
        ->spec->toBe('Esboço salvo');
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
