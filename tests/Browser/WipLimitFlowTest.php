<?php

use App\Enums\ActivityStatus;
use App\Exceptions\DoingWipLimitExceededException;
use App\Models\Activity;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * Which column a card is actually rendered in, read from the DOM: the board
 * renders one `<ul wire:sort:group-id="{status}">` per column, so a card's
 * column is the group id of the list it currently sits in.
 *
 * Attributes are matched with `hasAttribute` rather than CSS escapes,
 * because `wire:sort:group-id` needs escaping that survives neither the
 * heredoc nor the formatter cleanly.
 *
 * @param  string  $title  Substring of the card's title.
 */
function columnOfCardScript(string $title): string
{
    $needle = json_encode($title);

    return <<<JS
    (() => {
        const lists = Array.from(document.querySelectorAll('ul'))
            .filter(el => el.hasAttribute('wire:sort:group-id'));

        for (const list of lists) {
            const found = Array.from(list.children)
                .some(card => card.textContent.includes({$needle}));

            if (found) {
                return list.getAttribute('wire:sort:group-id');
            }
        }

        return 'not-rendered';
    })()
    JS;
}

/**
 * Drive the very call `wire:sort` makes when a card is dropped. The drop is
 * deliberately optimistic — nothing is pre-blocked client-side — so this is
 * the honest way to exercise "the card moves, the guard refuses, the card
 * comes back".
 */
function dropCardScript(int $id, string $column): string
{
    return <<<JS
    (() => {
        // The board component is the one owning the sortable lists.
        const list = Array.from(document.querySelectorAll('ul'))
            .find(el => el.hasAttribute('wire:sort'));
        let root = list;
        while (root && ! root.hasAttribute('wire:id')) {
            root = root.parentElement;
        }

        window.Livewire.find(root.getAttribute('wire:id')).call('handleSort', {$id}, 0, '{$column}');
    })()
    JS;
}

test('a card refused by the WIP limit comes back to its column and the user is told why', function (): void {
    Activity::factory()->issue()->doing()->count(2)->create();
    $third = Activity::factory()->issue()->todo()->create(['title' => 'Terceiro item do board']);

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('2/2')
        ->assertScript(columnOfCardScript('Terceiro item do board'), ActivityStatus::Todo->value);

    $page->script(dropCardScript($third->id, ActivityStatus::Doing->value));

    $page->waitForText(DoingWipLimitExceededException::messageFor(2))
        ->assertNoJavaScriptErrors()
        // The re-render is what puts the card back — assert it is visibly
        // in Pronto again, not merely unchanged in the database.
        ->assertScript(columnOfCardScript('Terceiro item do board'), ActivityStatus::Todo->value)
        ->assertSee('2/2');

    expect($third->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('an Emergência dropped into a full Fazendo lands there and the header reads 3/2', function (): void {
    Activity::factory()->issue()->doing()->count(2)->create();
    $emergency = Activity::factory()->issue()->todo()->emergency('Produção fora do ar')
        ->create(['title' => 'Incêndio que fura o limite']);

    $page = visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->assertSee('2/2');

    $page->script(dropCardScript($emergency->id, ActivityStatus::Doing->value));

    $page->waitForText('3/2')
        ->assertNoJavaScriptErrors()
        ->assertScript(columnOfCardScript('Incêndio que fura o limite'), ActivityStatus::Doing->value);

    expect($emergency->fresh()->status)->toBe(ActivityStatus::Doing);
});
