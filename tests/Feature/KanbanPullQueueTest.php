<?php

use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\ActivityStatusChange;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * The Pronto column as a pull queue (issue #144): rendered in the order the
 * service ranks, with no way to rearrange it by hand.
 */
function readyInPronto(Activity $activity, string $at): Activity
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

test('the Pronto column renders in pull-queue order, not sort_order', function () {
    // sort_order is deliberately the inverse of the queue order, so a
    // column still sorting by it would fail this test.
    $fifo = readyInPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Terceira da fila', 'sort_order' => 0]),
        '2026-08-01 09:00'
    );
    $atRisk = readyInPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(2)->toDateString())
            ->create(['title' => 'Segunda da fila', 'sort_order' => 1]),
        '2026-08-02 09:00'
    );
    $emergency = readyInPronto(
        Activity::factory()->issue()->todo()->emergency('Produção fora do ar')
            ->create(['title' => 'Primeira da fila', 'sort_order' => 2]),
        '2026-08-03 09:00'
    );

    Livewire::test('pages::kanban')
        ->assertSeeHtmlInOrder(['Primeira da fila', 'Segunda da fila', 'Terceira da fila']);

    expect([$emergency->sort_order, $atRisk->sort_order, $fifo->sort_order])->toBe([2, 1, 0]);
});

test('the Pronto column shows a divider per degrau', function () {
    readyInPronto(
        Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create(),
        '2026-08-01 09:00'
    );
    readyInPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(1)->toDateString())->create(),
        '2026-08-02 09:00'
    );
    readyInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-03 09:00');

    Livewire::test('pages::kanban')
        ->assertSeeHtmlInOrder(['Emergência', 'Data fixa em risco', 'Ordem de chegada'])
        ->assertSeeHtml('Ordem automática da fila');
});

test('a Pronto card carries the motivo of its position in a tooltip', function () {
    readyInPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDays(3)->toDateString())->create(),
        '2026-08-02 09:00'
    );

    Livewire::test('pages::kanban')->assertSeeHtml('em risco: faltam 3 dias');
});

test('a Pronto card keeps its service class badge', function () {
    readyInPronto(Activity::factory()->issue()->todo()->intangible()->create(), '2026-08-02 09:00');

    Livewire::test('pages::kanban')->assertSeeHtml('Intangível');
});

test('the Pronto list disables sorting inside itself while staying a drop target', function () {
    readyInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');

    $html = Livewire::test('pages::kanban')->html();

    // Everything between the Pronto <ul ...> tag's attributes.
    $prontoList = str($html)->after('wire:sort:group-id="todo"')->before('>')->toString();

    // `sort: false` is the only thing that stops Sortable rearranging the
    // list; the group binding is what keeps drops in and out working.
    expect($prontoList)->toContain('wire:sort:config="{ sort: false }"');
    expect($html)->toContain('wire:sort:group="tasks"');

    // No other column gives up sorting.
    expect(substr_count($html, 'wire:sort:config'))->toBe(1);
});

test('the degrau headings use full Tailwind classes so they actually get colour', function () {
    readyInPronto(
        Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create(),
        '2026-08-01 09:00'
    );
    readyInPronto(
        Activity::factory()->issue()->todo()->fixedDate(today()->addDay()->toDateString())->create(),
        '2026-08-02 09:00'
    );
    readyInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-03 09:00');

    $html = Livewire::test('pages::kanban')->html();

    // Interpolated colours (text-{{ ... }}-400/80) would never be built by
    // Tailwind's scanner, so the literals have to reach the markup intact.
    expect($html)->toContain('text-red-400/80');
    expect($html)->toContain('text-amber-400/80');
    expect($html)->toContain('text-zinc-400/80');
});

test('the Pronto column has no drag handle to reorder with', function () {
    $ready = readyInPronto(Activity::factory()->issue()->todo()->create(['title' => 'Item pronto']), '2026-08-01 09:00');
    Activity::factory()->issue()->backlog()->create(['title' => 'Item no backlog']);

    $html = Livewire::test('pages::kanban')->html();

    // The board still has handles (Backlog has one); the Pronto card doesn't.
    expect($html)->toContain('wire:sort:handle');

    $prontoCard = str($html)->after('wire:key="task-'.$ready->id.'"')->before('</li>')->toString();

    expect($prontoCard)->toContain('Item pronto');
    expect($prontoCard)->not->toContain('wire:sort:handle');
});

test('dropping a card at another position inside Pronto changes nothing', function () {
    $first = readyInPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Chegou primeiro', 'sort_order' => 0]),
        '2026-08-01 09:00'
    );
    $second = readyInPronto(
        Activity::factory()->issue()->todo()->create(['title' => 'Chegou depois', 'sort_order' => 1]),
        '2026-08-02 09:00'
    );

    Livewire::test('pages::kanban')
        ->call('handleSort', $second->id, 0, 'todo')
        ->assertSeeHtmlInOrder(['Chegou primeiro', 'Chegou depois']);

    // Nothing was renumbered either: there is no manual order to write.
    expect($first->fresh()->sort_order)->toBe(0);
    expect($second->fresh()->sort_order)->toBe(1);
});

test('moving a card into Pronto still works and puts it at the end of the FIFO', function () {
    readyInPronto(Activity::factory()->issue()->todo()->create(['title' => 'Ja estava pronto']), '2026-08-01 09:00');
    $incoming = Activity::factory()->issue()->backlog()->create(['title' => 'Acabou de chegar']);

    Livewire::test('pages::kanban')
        ->call('handleSort', $incoming->id, 0, 'todo')
        ->assertSeeHtmlInOrder(['Ja estava pronto', 'Acabou de chegar']);

    expect($incoming->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('moving a card out of Pronto still works', function () {
    $leaving = readyInPronto(Activity::factory()->issue()->todo()->create(), '2026-08-01 09:00');

    Livewire::test('pages::kanban')->call('handleSort', $leaving->id, 0, 'doing');

    expect($leaving->fresh()->status)->toBe(ActivityStatus::Doing);
});
