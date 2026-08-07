<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\DoingWipLimitExceededException;
use App\Models\Activity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('dropping a third standard card into Fazendo is refused and the card stays put', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $third = Activity::factory()->issue()->todo()->create(['title' => 'Terceiro item']);

    Livewire::test('pages::kanban')->call('handleSort', $third->id, 0, 'doing');

    // No client-side pre-block: the drop is optimistic and the re-render
    // that follows this request is what puts the card back.
    expect($third->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('the refusal actually reaches the user as a danger toast carrying the domain message', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $third = Activity::factory()->issue()->todo()->create();

    Livewire::test('pages::kanban')
        ->call('handleSort', $third->id, 0, 'doing')
        // Flux::toast dispatches `toast-show`; asserting the payload is what
        // stops a silently swallowed exception from passing as a refusal.
        ->assertDispatched('toast-show', function (string $event, array $params): bool {
            return ($params['dataset']['variant'] ?? null) === 'danger'
                && ($params['slots']['text'] ?? '') === DoingWipLimitExceededException::messageFor(2);
        });

    expect($third->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('dropping an Emergência into a full Fazendo column succeeds', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $emergency = Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create();

    Livewire::test('pages::kanban')->call('handleSort', $emergency->id, 0, 'doing');

    expect($emergency->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('the Fazendo header shows n/limit, in red once the column is full', function () {
    Activity::factory()->issue()->doing()->count(2)->create();

    Livewire::test('pages::kanban')
        ->assertSee('2/2')
        ->assertSee('Limite de 2 itens em Fazendo');

    expect(Livewire::test('pages::kanban')->instance()->wipBadgeColor())->toBe('red');
});

test('the n/limit badge is not red while there is still room', function () {
    Activity::factory()->issue()->doing()->create();

    $component = Livewire::test('pages::kanban')->assertSee('1/2');

    expect($component->instance()->wipBadgeColor())
        ->toBe(ActivityStatus::Doing->color())
        ->not->toBe('red');
});

test('the Fazendo header reads 3/2 in red when an Emergência has furado o limite', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    Activity::factory()->issue()->doing()->emergency('Produção fora do ar')->create();

    $component = Livewire::test('pages::kanban')->assertSee('3/2');

    expect($component->instance()->wipBadgeColor())->toBe('red');
});

test('the n/limit indicator ignores column filters, because the limit is about the whole board', function () {
    Activity::factory()->issue()->doing()->count(2)->create();

    Livewire::test('pages::kanban')
        ->set('filterServiceClass', ServiceClass::Intangible->value)
        ->assertSee('2/2');
});

test('an Emergência card carries its motivo in the badge tooltip', function () {
    Activity::factory()->issue()->backlog()->emergency('Produção fora do ar')->create([
        'title' => 'Hotfix do checkout',
    ]);

    Livewire::test('pages::kanban')
        ->assertSee('Emergência')
        ->assertSee('Produção fora do ar');
});
