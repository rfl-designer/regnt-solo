<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * Put an activity in Pronto at a given moment, the way the board does it:
 * the queue reads the last `to_status = todo` status change, so the history
 * — not `created_at`, not `sort_order` — is what decides the FIFO.
 */
function enterPronto(Activity $activity, string $at): Activity
{
    ActivityStatusChange::query()->where('activity_id', $activity->id)->delete();

    ActivityStatusChange::factory()->create([
        'activity_id' => $activity->id,
        'from_status' => ActivityStatus::Backlog,
        'to_status' => ActivityStatus::Todo,
        'changed_at' => Carbon::parse($at),
    ]);

    return $activity;
}

/**
 * The rendered Pronto column, read straight off the DOM in visual order:
 * degrau dividers come back inside brackets, cards come back as their title.
 *
 * This is the whole point of the browser check — the Livewire test can only
 * assert the order of the returned HTML, while this asserts the order the
 * user actually sees after Sortable and Alpine have mounted.
 */
function prontoSequenceScript(): string
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
 * The options of the live SortableJS instance mounted on a column's list.
 *
 * Sortable stores itself on the element under a timestamped `Sortable…`
 * property, so it is found by prefix. Reading the instance (rather than the
 * `wire:sort:config` attribute) is what proves the drag behaviour is really
 * configured in the browser: `sort` off, group still pulling and putting.
 */
function sortableOptionsScript(string $groupId): string
{
    $needle = json_encode($groupId);

    return <<<JS
    (() => {
        const list = Array.from(document.querySelectorAll('ul'))
            .find(el => el.getAttribute('wire:sort:group-id') === {$needle});

        if (! list) {
            return 'not-rendered';
        }

        const key = Object.keys(list).find(name => name.startsWith('Sortable'));

        if (! key) {
            return 'no-sortable-instance';
        }

        const options = list[key].options;

        return [
            'sort=' + options.sort,
            'group=' + options.group.name,
            'pull=' + options.group.pull,
            'put=' + options.group.put,
        ].join(',');
    })()
    JS;
}

/**
 * Every tooltip Flux rendered on the page. Flux keeps the content in a
 * hidden `[data-flux-tooltip]` element until hover, so the motivo of a
 * card's position is read from the DOM rather than from visible text.
 */
function pullQueueTooltipsScript(): string
{
    return <<<'JS'
    (() => Array.from(document.querySelectorAll('[data-flux-tooltip]'))
        .map(el => el.textContent.replace(/\s+/g, ' ').trim())
        .join(' | '))()
    JS;
}

/**
 * Drive the exact call `wire:sort` makes on a drop, from the browser. The
 * drop is optimistic client-side, so this is the honest way to exercise
 * "the card was let go here, now what does the server do".
 */
function pullQueueDropScript(int $id, int $position, string $column): string
{
    return <<<JS
    (() => {
        const list = Array.from(document.querySelectorAll('ul'))
            .find(el => el.hasAttribute('wire:sort'));
        let root = list;
        while (root && ! root.hasAttribute('wire:id')) {
            root = root.parentElement;
        }

        window.Livewire.find(root.getAttribute('wire:id')).call('handleSort', {$id}, {$position}, '{$column}');
    })()
    JS;
}

test('the Pronto column renders in queue order with a divider opening each degrau', function (): void {
    // sort_order is the inverse of the queue order on purpose: a column
    // still honouring the hand-made order would render this backwards.
    enterPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Chegou ha mais tempo', 'sort_order' => 0]),
        '2026-08-01 09:00'
    );
    enterPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Chegou depois', 'sort_order' => 1]),
        '2026-08-02 09:00'
    );
    enterPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(2)->toDateString())
            ->create(['title' => 'Data fixa apertada', 'sort_order' => 2]),
        '2026-08-03 09:00'
    );
    enterPronto(
        Activity::factory()->issue()->todo()->emergency('Producao fora do ar')
            ->create(['title' => 'Incendio na producao', 'sort_order' => 3]),
        '2026-08-04 09:00'
    );

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Ordem automática da fila')
        ->assertScript(prontoSequenceScript(), implode(' > ', [
            '[Emergência]',
            'Incendio na producao',
            '[Data fixa em risco]',
            'Data fixa apertada',
            '[Ordem de chegada]',
            'Chegou ha mais tempo',
            'Chegou depois',
        ]));
});

test('a Pronto card keeps its service class badge next to the degrau it landed on', function (): void {
    enterPronto(
        Activity::factory()->issue()->todo()->intangible()->create(['title' => 'Melhoria interna']),
        '2026-08-01 09:00'
    );

    $cardBadgesScript = <<<'JS'
    (() => {
        const list = Array.from(document.querySelectorAll('ul'))
            .find(el => el.getAttribute('wire:sort:group-id') === 'todo');
        const card = Array.from(list.children)
            .find(item => item.textContent.includes('Melhoria interna'));

        return card ? card.textContent.replace(/\s+/g, ' ').trim() : 'not-rendered';
    })()
    JS;

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Melhoria interna');

    expect($page->script($cardBadgesScript))->toContain('Intangível');
});

test('a Pronto card carries the motivo of its position in its tooltip', function (): void {
    enterPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(3)->toDateString())
            ->create(['title' => 'Entrega com data']),
        '2026-08-02 09:00'
    );
    enterPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Entrega sem data']),
        '2026-08-02 09:00'
    );

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Entrega com data');

    expect($page->script(pullQueueTooltipsScript()))
        ->toContain('em risco: faltam 3 dias')
        ->toContain('ordem de chegada: em Pronto');
});

test('the live Sortable on Pronto has sorting off while still pulling and putting', function (): void {
    enterPronto(Activity::factory()->issue()->todo()->create(['title' => 'Item pronto']), '2026-08-01 09:00');
    Activity::factory()->issue()->backlog()->create(['title' => 'Item no backlog']);

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('Item pronto');

    // Pronto: no rearranging inside the list, but the card can still be
    // pulled out of it and cards from other columns can still be put in.
    expect($page->script(sortableOptionsScript(ActivityStatus::Todo->value)))
        ->toContain('sort=false')
        ->toContain('group=tasks')
        ->not->toContain('pull=false')
        ->not->toContain('put=false');

    // Every other column keeps its hand-made order.
    expect($page->script(sortableOptionsScript(ActivityStatus::Backlog->value)))
        ->toContain('sort=true');
});

test('the Pronto card has no drag handle to reorder with', function (): void {
    enterPronto(Activity::factory()->issue()->todo()->create(['title' => 'Item pronto']), '2026-08-01 09:00');
    Activity::factory()->issue()->backlog()->create(['title' => 'Item no backlog']);

    $handlesScript = <<<'JS'
    (() => {
        const columnOf = title => {
            const lists = Array.from(document.querySelectorAll('ul'))
                .filter(el => el.hasAttribute('wire:sort:group-id'));

            for (const list of lists) {
                const card = Array.from(list.children)
                    .find(item => item.textContent.includes(title));

                if (card) {
                    return card.querySelector('[wire\\:sort\\:handle]') ? 'com-handle' : 'sem-handle';
                }
            }

            return 'not-rendered';
        };

        return columnOf('Item pronto') + ',' + columnOf('Item no backlog');
    })()
    JS;

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertScript($handlesScript, 'sem-handle,com-handle');
});

test('dropping a Pronto card at another position leaves the queue order untouched', function (): void {
    $first = enterPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Chegou primeiro', 'sort_order' => 0]),
        '2026-08-01 09:00'
    );
    $second = enterPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Chegou depois', 'sort_order' => 1]),
        '2026-08-02 09:00'
    );

    $expected = '[Ordem de chegada] > Chegou primeiro > Chegou depois';

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertScript(prontoSequenceScript(), $expected);

    // The drop the user could still perform by dragging the card onto the
    // top of the list: the server must not read it as a new order.
    $page->script(pullQueueDropScript($second->id, 0, ActivityStatus::Todo->value));

    $page->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertScript(prontoSequenceScript(), $expected);

    expect($first->fresh()->sort_order)->toBe(0)
        ->and($second->fresh()->sort_order)->toBe(1)
        ->and($second->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('a card dropped into Pronto joins the end of the FIFO', function (): void {
    enterPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Ja estava pronto']),
        '2026-08-01 09:00'
    );
    $incoming = Activity::factory()->issue()->backlog()->create(['title' => 'Acabou de chegar']);

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertScript(prontoSequenceScript(), '[Ordem de chegada] > Ja estava pronto');

    // Dropped at the top of the list — and it still lands at the end,
    // because the queue, not the drop position, decides.
    $page->script(pullQueueDropScript($incoming->id, 0, ActivityStatus::Todo->value));

    $page->waitForText('Acabou de chegar')
        ->assertNoJavaScriptErrors()
        ->assertScript(
            prontoSequenceScript(),
            '[Ordem de chegada] > Ja estava pronto > Acabou de chegar'
        );

    expect($incoming->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('a card dragged out of Pronto leaves the column', function (): void {
    $leaving = enterPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Vai para fazendo']),
        '2026-08-01 09:00'
    );

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertScript(prontoSequenceScript(), '[Ordem de chegada] > Vai para fazendo');

    $page->script(pullQueueDropScript($leaving->id, 0, ActivityStatus::Doing->value));

    $page->waitForText('1/2')
        ->assertNoJavaScriptErrors()
        // The Pronto column is empty again — the drag out really moved it.
        ->assertScript(prontoSequenceScript(), '[Nenhuma task]');

    expect($leaving->fresh()->status)->toBe(ActivityStatus::Doing);
});
