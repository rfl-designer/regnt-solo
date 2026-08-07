<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\DoingWipLimitExceededException;
use App\Exceptions\EmergencyRequiresReasonException;
use App\Exceptions\SingleActiveEmergencyException;
use App\Models\Activity;

/*
|--------------------------------------------------------------------------
| WIP limit on "Fazendo"
|--------------------------------------------------------------------------
*/

test('the wip limit lives in config and defaults to 2', function () {
    expect(config('soloboard.wip_limit_doing'))->toBe(2);
});

test('a third standard item moved into doing is refused at the eloquent seam', function () {
    Activity::factory()->issue()->doing()->count(2)->create();

    $third = Activity::factory()->issue()->todo()->create();

    expect(fn () => $third->update(['status' => ActivityStatus::Doing]))
        ->toThrow(DoingWipLimitExceededException::class, DoingWipLimitExceededException::messageFor(2));

    expect($third->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('a third standard item created directly in doing is refused too', function () {
    Activity::factory()->issue()->doing()->count(2)->create();

    expect(fn () => Activity::factory()->issue()->doing()->create())
        ->toThrow(DoingWipLimitExceededException::class);
});

test('the second item still gets in — the limit is a cap, not an off switch', function () {
    Activity::factory()->issue()->doing()->create();

    $second = Activity::factory()->issue()->todo()->create();
    $second->update(['status' => ActivityStatus::Doing]);

    expect($second->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('an emergency gets into doing even with the column already full', function () {
    Activity::factory()->issue()->doing()->count(2)->create();

    $emergency = Activity::factory()->issue()->todo()->emergency()->create();
    $emergency->update(['status' => ActivityStatus::Doing]);

    expect($emergency->fresh()->status)->toBe(ActivityStatus::Doing)
        ->and(Activity::query()->leaf()->where('status', ActivityStatus::Doing)->count())->toBe(3);
});

test('editing an item already in doing is never refused, even over the limit', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $emergency = Activity::factory()->issue()->doing()->emergency()->create();

    $stuck = Activity::query()->leaf()->where('status', ActivityStatus::Doing)->first();
    $stuck->update(['title' => 'Renomeada com a coluna cheia']);

    expect($stuck->fresh()->title)->toBe('Renomeada com a coluna cheia')
        ->and($emergency->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('the limit is honoured from any origin, including a bare tinker-style save', function () {
    Activity::factory()->issue()->doing()->count(2)->create();

    $task = Activity::factory()->issue()->backlog()->create();
    $fetched = Activity::query()->whereKey($task->id)->first();
    $fetched->status = ActivityStatus::Doing;

    expect(fn () => $fetched->save())->toThrow(DoingWipLimitExceededException::class);
});

test('a configured limit of 1 is respected', function () {
    config()->set('soloboard.wip_limit_doing', 1);

    Activity::factory()->issue()->doing()->create();

    expect(fn () => Activity::factory()->issue()->doing()->create())
        ->toThrow(DoingWipLimitExceededException::class, DoingWipLimitExceededException::messageFor(1));
});

/*
|--------------------------------------------------------------------------
| Emergência: motivo obrigatório
|--------------------------------------------------------------------------
*/

test('classifying as emergency without a motivo is refused', function () {
    $task = Activity::factory()->issue()->todo()->create();

    expect(fn () => $task->update(['service_class' => ServiceClass::Emergency]))
        ->toThrow(EmergencyRequiresReasonException::class, EmergencyRequiresReasonException::MESSAGE);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('a whitespace-only motivo does not count as a motivo', function () {
    $task = Activity::factory()->issue()->todo()->create();

    expect(fn () => $task->update([
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => '   ',
    ]))->toThrow(EmergencyRequiresReasonException::class);
});

test('classifying as emergency with a motivo succeeds and stamps emergency_since', function () {
    $task = Activity::factory()->issue()->todo()->create();

    $task->update([
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => '  Produção fora do ar  ',
    ]);

    $task->refresh();

    expect($task->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->emergency_reason)->toBe('Produção fora do ar')
        ->and($task->emergency_since)->not->toBeNull()
        ->and($task->emergencyDays())->toBe(0);
});

test('the motivo is cleared when the item is reclassified to another class', function () {
    $task = Activity::factory()->issue()->todo()->emergency('Cliente parado')->create();

    $task->update(['service_class' => ServiceClass::Standard]);
    $task->refresh();

    expect($task->emergency_reason)->toBeNull()
        ->and($task->emergency_since)->toBeNull();
});

test('the motivo survives concluding the emergency', function () {
    $task = Activity::factory()->issue()->doing()->emergency('Cliente parado')->create();

    $task->update(['status' => ActivityStatus::Done]);
    $task->refresh();

    expect($task->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->emergency_reason)->toBe('Cliente parado')
        ->and($task->emergency_since)->not->toBeNull()
        ->and($task->isActiveEmergency())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Emergência: uma só ativa
|--------------------------------------------------------------------------
*/

test('a second active emergency is refused and the exception carries the active one', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Produção fora do ar')->create([
        'title' => 'Hotfix do checkout',
    ]);
    $active->forceFill(['emergency_since' => now()->subDays(3)])->saveQuietly();

    $second = Activity::factory()->issue()->todo()->create();

    try {
        $second->update([
            'service_class' => ServiceClass::Emergency,
            'emergency_reason' => 'Outro incêndio',
        ]);

        $this->fail('Expected SingleActiveEmergencyException.');
    } catch (SingleActiveEmergencyException $e) {
        expect($e->activeEmergency->id)->toBe($active->id)
            ->and($e->activeEmergencyContext())->toBe([
                'id' => $active->id,
                'title' => 'Hotfix do checkout',
                'reason' => 'Produção fora do ar',
                'age_in_days' => 3,
            ])
            ->and($e->getMessage())->toContain('Hotfix do checkout');
    }

    expect($second->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('a done emergency does not occupy the slot', function () {
    Activity::factory()->issue()->done()->emergency('Já resolvido')->create();

    $new = Activity::factory()->issue()->todo()->create();
    $new->update(['service_class' => ServiceClass::Emergency, 'emergency_reason' => 'Novo incêndio']);

    expect($new->fresh()->service_class)->toBe(ServiceClass::Emergency);
});

test('bringing a done emergency back onto the board is refused while another is active', function () {
    $done = Activity::factory()->issue()->done()->emergency('Resolvido ontem')->create();
    Activity::factory()->issue()->todo()->emergency('Incêndio atual')->create();

    expect(fn () => $done->update(['status' => ActivityStatus::Todo]))
        ->toThrow(SingleActiveEmergencyException::class);

    expect($done->fresh()->status)->toBe(ActivityStatus::Done);
});

test('the active emergency can be edited and moved without tripping over itself', function () {
    $emergency = Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create();

    $emergency->update(['title' => 'Novo título', 'status' => ActivityStatus::Doing]);

    expect($emergency->fresh()->title)->toBe('Novo título')
        ->and($emergency->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('demoting the Emergência that was furando o limite is refused while Fazendo is full', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $emergency = Activity::factory()->issue()->doing()->emergency('Produção fora do ar')->create();

    // The column reads 3/2 only because an Emergência is exempt. Dropping
    // that exemption in place would leave three ordinary items in Fazendo,
    // so the demotion has to be refused — take something out first.
    expect(fn () => $emergency->update(['service_class' => ServiceClass::Standard]))
        ->toThrow(DoingWipLimitExceededException::class);

    expect($emergency->fresh()->service_class)->toBe(ServiceClass::Emergency);
});

test('demoting an Emergência in Fazendo is fine when the column has room', function () {
    Activity::factory()->issue()->doing()->create();
    $emergency = Activity::factory()->issue()->doing()->emergency('Produção fora do ar')->create();

    $emergency->update(['service_class' => ServiceClass::Standard]);

    expect($emergency->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('demoting an Emergência outside Fazendo is never gated by the WIP limit', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $emergency = Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create();

    $emergency->update(['service_class' => ServiceClass::Standard]);

    expect($emergency->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('concluding an Emergência and classifying in the same save is judged by the final state', function () {
    Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create();
    $task = Activity::factory()->issue()->doing()->create();

    // The final state is a *concluded* Emergência, which holds no slot. A
    // single save that lands there must be accepted, and it is — the guard
    // only ever sees the values the save is about to write.
    $task->update([
        'status' => ActivityStatus::Done,
        'service_class' => ServiceClass::Emergency,
        'emergency_reason' => 'Virou incêndio e foi resolvido',
    ]);

    expect($task->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->fresh()->status)->toBe(ActivityStatus::Done)
        ->and($task->fresh()->isActiveEmergency())->toBeFalse();
});

test('demoting the active emergency frees the slot for the next one — the two-step swap', function () {
    $current = Activity::factory()->issue()->doing()->emergency('Incêndio antigo')->create();
    $next = Activity::factory()->issue()->todo()->create();

    $current->update(['service_class' => ServiceClass::Standard]);
    $next->update(['service_class' => ServiceClass::Emergency, 'emergency_reason' => 'Incêndio novo']);

    expect($current->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($current->fresh()->emergency_reason)->toBeNull()
        ->and($next->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($next->fresh()->emergency_reason)->toBe('Incêndio novo');
});

test('a draft classified as emergency does not occupy the board slot', function () {
    Activity::factory()->draft()->emergency('Ideia urgente')->create();

    $onBoard = Activity::factory()->issue()->todo()->create();
    $onBoard->update(['service_class' => ServiceClass::Emergency, 'emergency_reason' => 'Incêndio real']);

    expect($onBoard->fresh()->service_class)->toBe(ServiceClass::Emergency);
});
