<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\BaselineCut;
use App\Models\User;
use App\Services\FlowMetricsService;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * As superfícies da fome de Intangível (issue #153): o card sempre visível
 * da página Fluxo e o banner sem dispensa do passo de puxar do ritual.
 *
 * As duas leem a mesma chamada de {@see FlowMetricsService},
 * então não têm como discordar sobre estar ou não com fome.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());

    Carbon::setTestNow('2026-08-10 12:00:00');

    // A migration semeia o corte de adoção com `cut_at = now()` do relógio
    // real; cada fixture declara o corte que quer medir.
    BaselineCut::query()->delete();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Um Intangível concluído no momento dado — o que zera a fome.
 */
function intangibleConcludedAt(string $at): Activity
{
    $activity = Activity::factory()->issue()->intangible()->done()->create();

    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => Carbon::parse($at)->subDays(2),
    ]);

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Todo,
        'to_status' => ActivityStatus::Done,
        'changed_at' => Carbon::parse($at),
    ]);

    return $activity;
}

/**
 * Um Intangível em Pronto, com chegada conhecida para a fila ser determinística.
 */
function intangibleInPronto(string $title, string $at): Activity
{
    $activity = Activity::factory()->issue()->intangible()->todo()->create(['title' => $title]);

    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => Carbon::parse($at),
    ]);

    return $activity;
}

// ── Página Fluxo ─────────────────────────────────────────────────────────

test('the Fluxo page always shows the Intangível card, even when the hunger is fed', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-06-01 12:00')]);
    intangibleConcludedAt('2026-08-07 12:00');

    Livewire::test('pages::flow')
        ->assertSee('Intangível')
        ->assertSeeHtml('data-test="intangible-card"')
        ->assertSeeHtml('data-test="intangible-fed"')
        ->assertDontSeeHtml('data-test="intangible-starving"')
        ->assertSee('última conclusão há 3 dias');
});

test('the Intangível card turns amber once the threshold is crossed', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-06-01 12:00')]);
    intangibleConcludedAt('2026-07-20 12:00');

    Livewire::test('pages::flow')
        ->assertSeeHtml('data-test="intangible-starving"')
        ->assertSeeHtml('border-amber-500/40')
        ->assertSee('última conclusão há 21 dias')
        ->assertSee('limiar de 14 dias');
});

test('the Intangível card reads the threshold from config', function () {
    config()->set('soloboard.intangible_starvation_days', 30);

    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-06-01 12:00')]);
    intangibleConcludedAt('2026-07-20 12:00');

    Livewire::test('pages::flow')
        ->assertSee('limiar de 30 dias')
        ->assertSeeHtml('data-test="intangible-fed"');
});

test('the Intangível card states the cold start anchored on the cut', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-07-01 12:00')]);

    Livewire::test('pages::flow')
        ->assertSee('nenhuma conclusão desde o corte, há 40 dias')
        ->assertSeeHtml('data-test="intangible-starving"');
});

test('the Intangível card is already amber on a board cut today', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-08-10 08:00')]);

    Livewire::test('pages::flow')
        ->assertSee('nenhuma conclusão desde o corte, feito hoje')
        ->assertSeeHtml('data-test="intangible-starving"')
        ->assertSeeHtml('border-amber-500/40')
        ->assertDontSeeHtml('data-test="intangible-fed"');
});

// ── Ritual matinal, passo 5 ──────────────────────────────────────────────

test('the pull step shows no banner while the hunger is fed', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-06-01 12:00')]);
    intangibleConcludedAt('2026-08-07 12:00');

    Livewire::test('pages::morning-ritual')
        ->set('step', 5)
        ->assertDontSeeHtml('data-test="ritual-intangible-banner"');
});

test('the pull step banner fires when the hunger is starving, with no way to dismiss it', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-07-01 12:00')]);
    intangibleInPronto('Refatorar o observer', '2026-08-01 09:00');

    $component = Livewire::test('pages::morning-ritual')->set('step', 5);

    $component
        ->assertSeeHtml('data-test="ritual-intangible-banner"')
        ->assertSee('Fome de Intangível: nenhuma conclusão desde o corte, há 40 dias')
        ->assertSeeHtml('data-test="ritual-intangible-shortcut"')
        ->assertSee('Refatorar o observer');

    // Sem dispensa: não há ação de fechar o banner nem estado que o esconda.
    expect(method_exists($component->instance(), 'dismissIntangibleBanner'))->toBeFalse();
    $component->assertDontSee('Dispensar');
});

test('the banner shortcut pulls the Intangível straight from the ritual', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-07-01 12:00')]);
    $intangible = intangibleInPronto('Refatorar o observer', '2026-08-01 09:00');

    Livewire::test('pages::morning-ritual')
        ->set('step', 5)
        ->call('pullItem', $intangible->id)
        ->assertSeeHtml('data-test="ritual-intangible-banner"')
        ->assertDontSeeHtml('data-test="ritual-intangible-shortcut"');

    expect($intangible->refresh()->status)->toBe(ActivityStatus::Doing);
});

test('with nothing Intangível in Pronto the banner still fires and points to Backlog and Ideias', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-07-01 12:00')]);

    // Uma Padrão em Pronto não serve de remédio: a despensa de Intangível
    // continua vazia.
    $standard = Activity::factory()->issue()->todo()->create(['title' => 'Padrão qualquer']);
    ActivityStatusChange::query()->where('activity_id', $standard->id)->delete();
    ActivityStatusChange::factory()->create([
        'activity_id' => $standard->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => Carbon::parse('2026-08-01 09:00'),
    ]);

    Livewire::test('pages::morning-ritual')
        ->set('step', 5)
        ->assertSeeHtml('data-test="ritual-intangible-banner"')
        ->assertSeeHtml('data-test="ritual-intangible-empty"')
        ->assertSee('E não há nenhum Intangível em Pronto.')
        ->assertSeeHtml('data-test="ritual-intangible-backlog"')
        ->assertSeeHtml('data-test="ritual-intangible-ideas"')
        ->assertDontSeeHtml('data-test="ritual-intangible-shortcut"');
});

test('the ritual banner fires on a board cut today, before any threshold could elapse', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-08-10 08:00')]);

    Livewire::test('pages::morning-ritual')
        ->set('step', 5)
        ->assertSeeHtml('data-test="ritual-intangible-banner"')
        ->assertSee('Fome de Intangível: nenhuma conclusão desde o corte, feito hoje')
        ->assertSeeHtml('data-test="ritual-intangible-empty"');
});

test('the banner belongs to the pull step only', function () {
    BaselineCut::factory()->create(['cut_at' => Carbon::parse('2026-07-01 12:00')]);

    Livewire::test('pages::morning-ritual')
        ->set('step', 4)
        ->assertDontSeeHtml('data-test="ritual-intangible-banner"');
});
