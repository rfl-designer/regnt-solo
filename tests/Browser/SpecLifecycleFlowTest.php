<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\User;

/**
 * The Spec's life cycle in a real browser (issue #146): the timeline in the
 * Épico's modal and the shortcuts that move it, the flow efficiency read
 * off the same history, the "Spec em aprovação" tail of Pronto that reacts
 * to an Épico being moved, and the client wait ranking on Fluxo.
 *
 * The clock is deliberately left alone — `Carbon::setTestNow()` does not
 * reach the process serving the page — so every history is written relative
 * to the real `now()`, through the same `withSpecHistory()` helper the
 * Feature suites use.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * A moment $days ago, in the string form `withSpecHistory()` parses.
 */
function specMoment(float $days): string
{
    return now()->copy()->subMinutes((int) round($days * 1440))->toDateTimeString();
}

/**
 * Open the globally-mounted feature modal the way every caller does it: by
 * dispatching `open-feature-modal`. The modal lives in the sidebar layout,
 * so this works from whichever page the test happens to be on.
 */
function openFeatureModalScript(int $featureId): string
{
    return "window.Livewire.dispatch('open-feature-modal', { featureId: {$featureId} })";
}

/**
 * The rendered Pronto column in visual order: dividers come back inside
 * brackets, cards as their title — so "the blocked ones are at the end,
 * under their own divider" is asserted where the user sees it.
 */
function prontoWithSpecTailScript(): string
{
    return <<<'JS'
    (() => {
        const list = Array.from(document.querySelectorAll('ul'))
            .find(el => el.getAttribute('wire:sort:group-id') === 'todo');

        if (! list) {
            return 'not-rendered';
        }

        return Array.from(list.children)
            .map(item => {
                if (item.hasAttribute('wire:sort:item')) {
                    const title = item.querySelector('.line-clamp-2');

                    return title ? title.textContent.trim() : 'card-sem-titulo';
                }

                return '[' + item.textContent.replace(/\s+/g, ' ').trim() + ']';
            })
            .filter(text => text !== '[]')
            .join(' > ');
    })()
    JS;
}

/**
 * The classes of a step on the Spec timeline, read off the DOM: which step
 * is lit is a visual claim, so it is asserted from the rendered element.
 */
function specStepClassScript(string $label): string
{
    $needle = json_encode($label);

    return <<<JS
    (() => {
        const timeline = document.querySelector('[data-test="spec-timeline"]');

        if (! timeline) {
            return 'not-rendered';
        }

        const step = Array.from(timeline.querySelectorAll('[wire\\\\:key^="spec-step-"]'))
            .find(el => el.textContent.includes({$needle}));

        return step ? step.className + ' ' + step.innerHTML.replace(/\s+/g, ' ') : 'sem-etapa';
    })()
    JS;
}

/**
 * The client names in the order the ranking rendered them.
 */
function clientWaitOrderScript(): string
{
    return <<<'JS'
    (() => {
        const panel = document.querySelector('[data-test="client-waits"]');

        if (! panel) {
            return 'not-rendered';
        }

        return Array.from(panel.querySelectorAll('[wire\\:key^="client-wait-"]'))
            .map(row => row.querySelector('.truncate').textContent.trim())
            .join(' > ');
    })()
    JS;
}

test('the Épico modal shows the four Spec dates, lights the current step and moves it from the shortcut', function (): void {
    $client = Client::factory()->create(['name' => 'Cliente da Spec']);

    $epic = withSpecHistory(
        Activity::factory()->epic()->awaitingValidation()->create([
            'title' => 'Epico com spec entregue',
            'client_id' => $client->id,
        ]),
        [
            [ActivityStatus::AwaitingApproval, specMoment(6)],
            [ActivityStatus::Todo, specMoment(5)],
            [ActivityStatus::AwaitingValidation, specMoment(2)],
        ]
    );

    $sentAt = now()->copy()->subDays(6)->format('d/m/Y H:i');

    $page = visit('/kanban')->assertNoJavaScriptErrors();

    $page->script(openFeatureModalScript($epic->id));

    $page->waitForText('Ciclo da Spec')
        ->assertSee('Enviada')
        ->assertSee('Aprovada')
        ->assertSee('Entregue')
        ->assertSee('Validada')
        // The dates come off the history, not off any column.
        ->assertSee($sentAt)
        ->assertNoJavaScriptErrors();

    // Entregue is the stage the Spec is sitting on; Aprovada is behind it.
    expect($page->script(specStepClassScript('Entregue')))->toContain('bg-violet-500');
    expect($page->script(specStepClassScript('Aprovada')))->toContain('bg-emerald-500/70');
    expect($page->script(specStepClassScript('Validada')))
        ->toContain('bg-zinc-700')
        ->toContain('—');

    // The shortcut is sugar over the status: it moves the Épico and the
    // move is what records the event.
    $page->click('[data-test="spec-send"]')
        ->waitForText('Spec enviada para aprovação')
        ->assertNoJavaScriptErrors();

    $epic->refresh();

    expect($epic->status)->toBe(ActivityStatus::AwaitingApproval)
        ->and($epic->statusChanges()->where('to_status', ActivityStatus::AwaitingApproval)->count())->toBe(2);
});

test('the modal reports the flow efficiency of an approved Spec', function (): void {
    $epic = withSpecHistory(
        Activity::factory()->epic()->doing()->create(['title' => 'Epico com eficiencia']),
        [
            [ActivityStatus::AwaitingApproval, specMoment(5)],
            [ActivityStatus::Doing, specMoment(4)],
        ]
    );

    // One day of touch inside a four-day window that is still open: 25%.
    withSpecHistory(
        Activity::factory()->issue()->todo()->create([
            'title' => 'Filho que tocou um dia',
            'parent_id' => $epic->id,
        ]),
        [
            [ActivityStatus::Doing, specMoment(3)],
            [ActivityStatus::Todo, specMoment(2)],
        ]
    );

    $page = visit('/kanban')->assertNoJavaScriptErrors();

    $page->script(openFeatureModalScript($epic->id));

    $page->waitForText('Eficiência de fluxo')
        ->assertSee('25%')
        ->assertSee('de toque em')
        ->assertSee('desde a aprovação (ainda contando)')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.querySelector(\'[data-test="spec-efficiency"]\') !== null'))->toBeTrue();
});

test('children of an unapproved Épico sit at the end of Pronto and rejoin the queue when it moves', function (): void {
    $client = Client::factory()->create(['name' => 'Cliente que demora']);

    $pending = withSpecHistory(
        Activity::factory()->epic()->awaitingApproval()->create([
            'title' => 'Epico esperando aprovacao',
            'client_id' => $client->id,
        ]),
        [[ActivityStatus::AwaitingApproval, specMoment(3)]]
    );

    $approved = withSpecHistory(
        Activity::factory()->epic()->todo()->create(['title' => 'Epico ja aprovado']),
        [
            [ActivityStatus::AwaitingApproval, specMoment(9)],
            [ActivityStatus::Todo, specMoment(8)],
        ]
    );

    // The blocked one arrived first, so a queue that ranked it would put it
    // on top. It doesn't rank: it goes to the tail, under its own divider.
    withSpecHistory(
        Activity::factory()->issue()->todo()->create([
            'title' => 'Filho sem spec aprovada',
            'parent_id' => $pending->id,
        ]),
        [[ActivityStatus::Todo, specMoment(7)]]
    );

    withSpecHistory(
        Activity::factory()->issue()->todo()->create([
            'title' => 'Filho com spec aprovada',
            'parent_id' => $approved->id,
        ]),
        [[ActivityStatus::Todo, specMoment(1)]]
    );

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Filho sem spec aprovada')
        ->assertSee('spec em aprovação');

    expect($page->script(prontoWithSpecTailScript()))
        ->toBe('[Ordem de chegada] > Filho com spec aprovada > [Spec em aprovação] > Filho sem spec aprovada');

    expect($page->script('document.querySelectorAll(\'[data-test="spec-pending-signal"]\').length'))->toBe(1);

    // Moving the Épico forward *is* the approval, and the board is
    // listening: delivering it for validation leaves Aguardando aprovação
    // ahead, so the child is committed work from that moment on.
    $page->script(openFeatureModalScript($pending->id));

    $page->waitForText('Ciclo da Spec')
        ->click('[data-test="spec-deliver"]')
        ->waitForText('Entregue para validação')
        ->assertNoJavaScriptErrors();

    // The board refreshed on `feature-updated`, with no card touched.
    // The assertion retries until the re-render lands.
    $page->assertDontSee('spec em aprovação')
        ->assertNoJavaScriptErrors();

    expect($page->script(prontoWithSpecTailScript()))
        ->toContain('Filho sem spec aprovada')
        ->not->toContain('[Spec em aprovação]');
});

test('the Fluxo page ranks the clients by how long they kept the board waiting', function (): void {
    $slow = Client::factory()->create(['name' => 'Cliente Lento']);
    $fast = Client::factory()->create(['name' => 'Cliente Rapido']);

    // 5 days of approval plus 2 of validation, over two items.
    withSpecHistory(
        Activity::factory()->epic()->todo()->create(['title' => 'Espera longa', 'client_id' => $slow->id]),
        [
            [ActivityStatus::AwaitingApproval, specMoment(10)],
            [ActivityStatus::Todo, specMoment(5)],
        ]
    );
    withSpecHistory(
        Activity::factory()->issue()->todo()->create(['title' => 'Espera media', 'client_id' => $slow->id]),
        [
            [ActivityStatus::AwaitingValidation, specMoment(4)],
            [ActivityStatus::Todo, specMoment(2)],
        ]
    );
    withSpecHistory(
        Activity::factory()->issue()->todo()->create(['title' => 'Espera curta', 'client_id' => $fast->id]),
        [
            [ActivityStatus::AwaitingApproval, specMoment(3)],
            [ActivityStatus::Todo, specMoment(2)],
        ]
    );

    // Internal waiting is not a client's fault and must not be billed to
    // one: this client waited two weeks on paper and still doesn't rank.
    $internal = Client::factory()->create(['name' => 'Cliente Interno']);

    withSpecHistory(
        Activity::factory()->issue()->doing()->create(['title' => 'Espera interna', 'client_id' => $internal->id]),
        [
            [ActivityStatus::Waiting, specMoment(20)],
            [ActivityStatus::Doing, specMoment(6)],
        ]
    );

    $page = visit('/flow')
        ->assertNoJavaScriptErrors()
        ->assertSee('Esperas por cliente')
        ->assertSeeIn('[data-test="client-waits"]', 'Cliente Lento')
        ->assertSeeIn('[data-test="client-waits"]', 'Cliente Rapido')
        ->assertSee('7,0 dias')
        ->assertSee('2 itens')
        ->assertSee('1,0 dias')
        ->assertSee('1 item')
        // The internal wait is nobody's client tab.
        ->assertDontSeeIn('[data-test="client-waits"]', 'Cliente Interno')
        ->assertNoJavaScriptErrors();

    // Descending, read off the rendered rows.
    expect($page->script(clientWaitOrderScript()))->toBe('Cliente Lento > Cliente Rapido');
});
