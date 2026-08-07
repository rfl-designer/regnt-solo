<?php

use App\Enums\ActivityStatus;

test('the enum has exactly 8 statuses', function () {
    expect(ActivityStatus::cases())->toHaveCount(8);
});

test('the new statuses have new string values, never reusing an existing one', function () {
    expect(ActivityStatus::AwaitingApproval->value)->toBe('awaiting_approval')
        ->and(ActivityStatus::Waiting->value)->toBe('waiting')
        ->and(ActivityStatus::AwaitingValidation->value)->toBe('awaiting_validation');
});

test('the renamed statuses keep their persisted value, only the label changes', function () {
    expect(ActivityStatus::Todo->value)->toBe('todo')
        ->and(ActivityStatus::Todo->label())->toBe('Pronto')
        ->and(ActivityStatus::Done->value)->toBe('done')
        ->and(ActivityStatus::Done->label())->toBe('Feito');
});

test('board order lists exactly the 7 board columns in flow order, excluding Inbox', function () {
    expect(ActivityStatus::boardOrder())->toBe([
        ActivityStatus::Backlog,
        ActivityStatus::AwaitingApproval,
        ActivityStatus::Todo,
        ActivityStatus::Doing,
        ActivityStatus::Waiting,
        ActivityStatus::AwaitingValidation,
        ActivityStatus::Done,
    ]);
});

test('colors and icons match the decided palette', function () {
    expect(ActivityStatus::Inbox->color())->toBe('zinc')
        ->and(ActivityStatus::Backlog->color())->toBe('slate')
        ->and(ActivityStatus::AwaitingApproval->color())->toBe('violet')
        ->and(ActivityStatus::AwaitingApproval->icon())->toBe('paper-airplane')
        ->and(ActivityStatus::Todo->color())->toBe('blue')
        ->and(ActivityStatus::Doing->color())->toBe('amber')
        ->and(ActivityStatus::Waiting->color())->toBe('orange')
        ->and(ActivityStatus::Waiting->icon())->toBe('pause-circle')
        ->and(ActivityStatus::AwaitingValidation->color())->toBe('teal')
        ->and(ActivityStatus::AwaitingValidation->icon())->toBe('eye')
        ->and(ActivityStatus::Done->color())->toBe('emerald');
});

test('isWaiting is true only for the three waiting statuses', function () {
    $waiting = array_values(array_filter(ActivityStatus::cases(), fn (ActivityStatus $s): bool => $s->isWaiting()));

    expect(array_map(fn (ActivityStatus $s) => $s->value, $waiting))
        ->toEqualCanonicalizing(['awaiting_approval', 'waiting', 'awaiting_validation']);
});

test('isClientWaiting is true only for the client-side waits', function () {
    expect(ActivityStatus::AwaitingApproval->isClientWaiting())->toBeTrue()
        ->and(ActivityStatus::AwaitingValidation->isClientWaiting())->toBeTrue()
        ->and(ActivityStatus::Waiting->isClientWaiting())->toBeFalse()
        ->and(ActivityStatus::Todo->isClientWaiting())->toBeFalse();
});

test('isInternalWaiting is true only for Esperando', function () {
    expect(ActivityStatus::Waiting->isInternalWaiting())->toBeTrue()
        ->and(ActivityStatus::AwaitingApproval->isInternalWaiting())->toBeFalse()
        ->and(ActivityStatus::AwaitingValidation->isInternalWaiting())->toBeFalse();
});

test('isWorkable is true only for Pronto and Fazendo', function () {
    $workable = array_values(array_filter(ActivityStatus::cases(), fn (ActivityStatus $s): bool => $s->isWorkable()));

    expect(array_map(fn (ActivityStatus $s) => $s->value, $workable))
        ->toEqualCanonicalizing(['todo', 'doing']);
});
