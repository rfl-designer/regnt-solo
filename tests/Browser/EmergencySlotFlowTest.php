<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Models\Activity;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * The service class select in the task modal, scoped to the open tab panel
 * so it never collides with the board's own filter select.
 */
$serviceClassSelect = '[role="tabpanel"] select[wire\\:model="serviceClass"]';

/**
 * "Manter a atual" and "Substituir" are rendered twice on every page: once
 * inline in the Task Modal and once in the shared Emergência modal the layout
 * keeps mounted in the sidebar. Clicking by label would be ambiguous, so the
 * buttons are addressed by the action they actually perform.
 */
function emergencyButton(string $action): string
{
    return 'button[wire\\:click="'.$action.'"]';
}

/**
 * The per-row service class select in the Inbox table, pinned by the task id
 * baked into its `wire:change` so a row is never confused with its neighbour.
 */
function inboxServiceClassSelect(int $taskId): string
{
    return 'select[wire\\:change="updateServiceClass('.$taskId.', $event.target.value)"]';
}

test('task modal offers Manter a atual / Substituir when a second Emergência would light up', function () use ($serviceClassSelect): void {
    $current = Activity::factory()->issue()->emergency('Banco de dados fora do ar')->create([
        'title' => 'Incendio que ja ocupa a vaga',
        'status' => ActivityStatus::Doing,
    ]);

    $challenger = Activity::factory()->issue()->create([
        'title' => 'Incendio novo que quer a vaga',
        'status' => ActivityStatus::Todo,
        'service_class' => ServiceClass::Standard,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Incendio novo que quer a vaga')
        ->waitForText('Classe de serviço')
        ->select($serviceClassSelect, ServiceClass::Emergency->value)
        ->waitForText('Motivo da Emergência')
        ->fill('textarea[wire\\:model="emergencyReason"]', 'Cliente parado desde ontem')
        ->click('Salvar')
        // A blocking choice, not a toast: the save is held until the user
        // says which of the two is the Emergência.
        ->waitForText('Já existe uma Emergência ativa')
        ->assertSee('Incendio que ja ocupa a vaga')
        ->assertSee('Banco de dados fora do ar')
        ->assertNoJavaScriptErrors();

    // Nothing is written while the question is on screen.
    expect($challenger->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($current->fresh()->service_class)->toBe(ServiceClass::Emergency);
});

test('substituting from the task modal hands the slot over in one move', function () use ($serviceClassSelect): void {
    $current = Activity::factory()->issue()->emergency('Banco de dados fora do ar')->create([
        'title' => 'Incendio antigo a ser rebaixado',
        'status' => ActivityStatus::Doing,
    ]);

    $challenger = Activity::factory()->issue()->create([
        'title' => 'Incendio novo a promover',
        'status' => ActivityStatus::Todo,
        'service_class' => ServiceClass::Intangible,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Incendio novo a promover')
        ->waitForText('Classe de serviço')
        ->select($serviceClassSelect, ServiceClass::Emergency->value)
        ->waitForText('Motivo da Emergência')
        ->fill('textarea[wire\\:model="emergencyReason"]', 'Producao fora do ar')
        ->click('Salvar')
        ->waitForText('Já existe uma Emergência ativa')
        // The conflict block is inserted into an already-open modal, so the
        // panel is still reflowing when the text first appears. Without
        // letting it settle, the click lands on "Manter a atual" — the
        // neighbouring button — and silently asserts the opposite decision.
        ->wait(1)
        ->click(emergencyButton('replaceEmergency'))
        ->waitForText('Task salva')
        // O toast aparece antes de o board terminar de se reconciliar, e as
        // asserções abaixo leem o banco. Sem deixar a troca assentar, elas
        // podem ler o estado de antes do swap e acusar uma substituição que
        // aconteceu — o teste mede a decisão, não a velocidade do render.
        ->wait(1)
        ->assertNoJavaScriptErrors();

    // The challenger starts as Intangível rather than Padrão on purpose: it
    // is what tells a real substitution apart from the "Manter a atual"
    // fallback, which would leave it on its own stored class.
    expect($challenger->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($challenger->fresh()->emergency_reason)->toBe('Producao fora do ar')
        ->and($current->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and(Activity::query()->activeEmergency()->count())->toBe(1);
});

test('keeping the current Emergência saves the task as Padrão instead', function () use ($serviceClassSelect): void {
    $current = Activity::factory()->issue()->emergency('Banco de dados fora do ar')->create([
        'title' => 'Incendio que deve permanecer',
        'status' => ActivityStatus::Doing,
    ]);

    $challenger = Activity::factory()->issue()->create([
        'title' => 'Incendio que desiste da vaga',
        'status' => ActivityStatus::Todo,
        'service_class' => ServiceClass::Standard,
    ]);

    visit('/kanban')
        ->assertNoJavaScriptErrors()
        ->click('Incendio que desiste da vaga')
        ->waitForText('Classe de serviço')
        ->select($serviceClassSelect, ServiceClass::Emergency->value)
        ->waitForText('Motivo da Emergência')
        ->fill('textarea[wire\\:model="emergencyReason"]', 'Achei que fosse urgente')
        ->click('Salvar')
        ->waitForText('Já existe uma Emergência ativa')
        ->wait(1)
        ->click(emergencyButton('keepCurrentEmergency'))
        ->waitForText('Task salva')
        ->assertNoJavaScriptErrors();

    expect($challenger->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($current->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and(Activity::query()->activeEmergency()->count())->toBe(1);
});

test('classifying from the Inbox raises the blocking motivo modal and refuses an empty one', function (): void {
    $task = Activity::factory()->issue()->create([
        'title' => 'Task da inbox para classificar',
        'status' => ActivityStatus::Inbox,
        'service_class' => ServiceClass::Standard,
    ]);

    visit('/inbox')
        ->assertNoJavaScriptErrors()
        ->assertSee('Task da inbox para classificar')
        ->select(inboxServiceClassSelect($task->id), ServiceClass::Emergency->value)
        // The Inbox never writes the classification itself — it defers to the
        // shared modal, which owns the mandatory motivo.
        ->waitForText('Por que isso é uma Emergência?')
        ->click('Classificar como Emergência')
        ->waitForText('Informe por que isso é uma Emergência.')
        ->assertNoJavaScriptErrors();

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard);

    visit('/inbox')
        ->select(inboxServiceClassSelect($task->id), ServiceClass::Emergency->value)
        ->waitForText('Por que isso é uma Emergência?')
        ->fill('textarea[wire\\:model="reason"]', 'Prazo legal vence hoje')
        ->click('Classificar como Emergência')
        ->waitForText('Emergência classificada')
        ->assertNoJavaScriptErrors();

    expect($task->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->fresh()->emergency_reason)->toBe('Prazo legal vence hoje');
});

test('the shared modal asks the conflict question first and only then the motivo', function (): void {
    $current = Activity::factory()->issue()->emergency('Servidor de producao caiu')->create([
        'title' => 'Emergencia vigente do board',
        'status' => ActivityStatus::Doing,
    ]);

    $task = Activity::factory()->issue()->create([
        'title' => 'Task da inbox que disputa a vaga',
        'status' => ActivityStatus::Inbox,
        'service_class' => ServiceClass::Standard,
    ]);

    visit('/inbox')
        ->assertNoJavaScriptErrors()
        ->select(inboxServiceClassSelect($task->id), ServiceClass::Emergency->value)
        // Conflict first: there is no point asking for a motivo before the
        // user has decided the new item is the Emergência at all.
        ->waitForText('Já existe uma Emergência ativa')
        ->assertSee('Emergencia vigente do board')
        ->assertSee('Servidor de producao caiu')
        ->wait(1)
        ->click(emergencyButton('replaceCurrent'))
        ->waitForText('Por que isso é uma Emergência?')
        // The consequence of substituting is spelled out before it happens.
        ->assertSee('Emergencia vigente do board volta para Padrão')
        ->fill('textarea[wire\\:model="reason"]', 'Cliente parado ha duas horas')
        ->click('Classificar como Emergência')
        ->waitForText('Emergência classificada')
        ->assertNoJavaScriptErrors();

    expect($task->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($task->fresh()->emergency_reason)->toBe('Cliente parado ha duas horas')
        ->and($current->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and(Activity::query()->activeEmergency()->count())->toBe(1);
});

test('keeping the current Emergência in the shared modal abandons the classification', function (): void {
    $current = Activity::factory()->issue()->emergency('Servidor de producao caiu')->create([
        'title' => 'Emergencia mantida do board',
        'status' => ActivityStatus::Doing,
    ]);

    $task = Activity::factory()->issue()->create([
        'title' => 'Task da inbox que abre mao',
        'status' => ActivityStatus::Inbox,
        'service_class' => ServiceClass::Standard,
    ]);

    visit('/inbox')
        ->assertNoJavaScriptErrors()
        ->select(inboxServiceClassSelect($task->id), ServiceClass::Emergency->value)
        ->waitForText('Já existe uma Emergência ativa')
        ->wait(1)
        ->click(emergencyButton('keepCurrent'))
        ->waitForText('Emergência mantida')
        ->assertSee('Emergencia mantida do board')
        ->assertNoJavaScriptErrors();

    expect($task->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($current->fresh()->service_class)->toBe(ServiceClass::Emergency);
});
