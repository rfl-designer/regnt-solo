<?php

use App\Enums\ActivityStatus;
use App\Enums\ServiceClass;
use App\Exceptions\DoingWipLimitExceededException;
use App\Exceptions\EmergencyRequiresReasonException;
use App\Exceptions\SingleActiveEmergencyException;
use App\Mcp\Servers\SoloBoardServer;
use App\Mcp\Tools\CreateIssueTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\PromoteStakeholderIssueToFeatureTool;
use App\Mcp\Tools\UpdateEpicTool;
use App\Mcp\Tools\UpdateIssueTool;
use App\Mcp\Tools\UpdateTaskTool;
use App\Models\Activity;
use App\Models\StakeholderIssue;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

test('update-issue refuses a third item into doing with the canonical WIP message', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $third = Activity::factory()->issue()->todo()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $third->id,
        'status' => 'doing',
    ]);

    $response->assertHasErrors([DoingWipLimitExceededException::messageFor(2)]);

    expect($third->fresh()->status)->toBe(ActivityStatus::Todo);
});

test('update-issue lets an Emergência into a full doing column', function () {
    Activity::factory()->issue()->doing()->count(2)->create();
    $emergency = Activity::factory()->issue()->todo()->emergency('Produção fora do ar')->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $emergency->id,
        'status' => 'doing',
    ]);

    $response->assertOk();

    expect($emergency->fresh()->status)->toBe(ActivityStatus::Doing);
});

test('classifying as emergency without a motivo is refused via MCP', function () {
    $issue = Activity::factory()->issue()->todo()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'service_class' => 'emergency',
    ]);

    $response->assertHasErrors([EmergencyRequiresReasonException::MESSAGE]);

    expect($issue->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('update-issue classifies an emergency when the motivo is given and echoes it back', function () {
    $issue = Activity::factory()->issue()->todo()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $issue->id,
        'service_class' => 'emergency',
        'emergency_reason' => 'Produção fora do ar',
    ]);

    $response->assertOk();
    $response->assertSee('"emergency_reason": "Produ');

    expect($issue->fresh()->service_class)->toBe(ServiceClass::Emergency);
});

test('a second emergency via MCP returns a genuinely structured error, not JSON glued to prose', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create([
        'title' => 'Hotfix do checkout',
    ]);
    $active->forceFill(['emergency_since' => now()->subDays(2)])->saveQuietly();

    $second = Activity::factory()->issue()->todo()->create();

    $response = SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $second->id,
        'service_class' => 'emergency',
        'emergency_reason' => 'Outro incêndio',
    ]);

    $response->assertHasErrors();

    // The payload travels in structuredContent, so a client reads fields
    // instead of parsing JSON out of the human message.
    $response->assertStructuredContent([
        'error' => 'single_active_emergency',
        'message' => SingleActiveEmergencyException::messageFor($active),
        'active_emergency' => [
            'id' => $active->id,
            'title' => 'Hotfix do checkout',
            'reason' => 'Incêndio atual',
            'age_in_days' => 2,
        ],
        'how_to_swap' => 'There is no force parameter. To swap, make two calls: first demote the active emergency (service_class="standard" on id '
            .$active->id.'), then classify the new one as "emergency" with an emergency_reason.',
    ]);

    expect($second->fresh()->service_class)->toBe(ServiceClass::Standard);
});

test('every tool that can light an Emergência answers a second one the same structured way', function (string $tool, callable $arguments) {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create([
        'title' => 'Hotfix do checkout',
    ]);

    $response = SoloBoardServer::tool($tool, $arguments());

    $response->assertHasErrors();
    $response->assertStructuredContent([
        'error' => 'single_active_emergency',
        'message' => SingleActiveEmergencyException::messageFor($active),
        'active_emergency' => [
            'id' => $active->id,
            'title' => 'Hotfix do checkout',
            'reason' => 'Incêndio atual',
            'age_in_days' => 0,
        ],
        'how_to_swap' => 'There is no force parameter. To swap, make two calls: first demote the active emergency (service_class="standard" on id '
            .$active->id.'), then classify the new one as "emergency" with an emergency_reason.',
    ]);
})->with([
    'create-task' => [CreateTaskTool::class, fn () => [
        'title' => 'Nova emergência',
        'service_class' => 'emergency',
        'emergency_reason' => 'Outro incêndio',
    ]],
    'create-issue' => [CreateIssueTool::class, fn () => [
        'title' => 'Nova emergência',
        'service_class' => 'emergency',
        'emergency_reason' => 'Outro incêndio',
    ]],
    'update-task' => [UpdateTaskTool::class, fn () => [
        'task_id' => Activity::factory()->task()->todo()->create()->id,
        'service_class' => 'emergency',
        'emergency_reason' => 'Outro incêndio',
    ]],
    'update-issue' => [UpdateIssueTool::class, fn () => [
        'issue_id' => Activity::factory()->issue()->todo()->create()->id,
        'service_class' => 'emergency',
        'emergency_reason' => 'Outro incêndio',
    ]],
]);

test('promote-stakeholder-issue may classify an Emergência because the feature it creates is off-board', function () {
    Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create();
    $issue = StakeholderIssue::factory()->toFeature()->create();

    $response = SoloBoardServer::tool(PromoteStakeholderIssueToFeatureTool::class, [
        'issue_id' => $issue->id,
        'title' => 'Feature emergencial',
        'service_class' => 'emergency',
        'emergency_reason' => 'Outro incêndio',
    ]);

    // The promoted feature is created with no board status, so it holds no
    // slot and conflicts with nobody. The guard only fires the moment
    // somebody actually puts it on the board.
    $response->assertOk();

    $feature = Activity::findOrFail($issue->fresh()->activity_id);

    expect($feature->status)->toBeNull()
        ->and($feature->isActiveEmergency())->toBeFalse();

    expect(fn () => $feature->update(['status' => ActivityStatus::Todo]))
        ->toThrow(SingleActiveEmergencyException::class);
});

test('update-epic translates the Emergência conflict instead of letting it escape', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio atual')->create([
        'title' => 'Hotfix do checkout',
    ]);

    // Epics can be created as Emergência by promote-stakeholder-issue, so
    // bringing a concluded one back onto the board is a real path into the
    // conflict — and update-epic must answer it like every other tool.
    $epic = Activity::factory()->epic()->done()->emergency('Incêndio resolvido')->create();

    $response = SoloBoardServer::tool(UpdateEpicTool::class, [
        'epic_id' => $epic->id,
        'status' => 'doing',
    ]);

    $response->assertHasErrors();
    $response->assertStructuredContent([
        'error' => 'single_active_emergency',
        'message' => SingleActiveEmergencyException::messageFor($active),
        'active_emergency' => [
            'id' => $active->id,
            'title' => 'Hotfix do checkout',
            'reason' => 'Incêndio atual',
            'age_in_days' => 0,
        ],
        'how_to_swap' => 'There is no force parameter. To swap, make two calls: first demote the active emergency (service_class="standard" on id '
            .$active->id.'), then classify the new one as "emergency" with an emergency_reason.',
    ]);

    expect($epic->fresh()->status)->toBe(ActivityStatus::Done);
});

test('there is no force parameter anywhere in the emergency-capable tools', function (string $tool) {
    $schema = array_keys((new $tool)->schema(new JsonSchemaTypeFactory));

    // Swapping the emergency must always be two explicit calls — a flag
    // letting a client override the invariant would make it advisory,
    // which is exactly what it must not be.
    expect($schema)->not->toContain('force')
        ->and($schema)->toContain('emergency_reason');
})->with([
    CreateTaskTool::class,
    CreateIssueTool::class,
    UpdateTaskTool::class,
    UpdateIssueTool::class,
    PromoteStakeholderIssueToFeatureTool::class,
]);

test('the documented two-call swap works over MCP', function () {
    $active = Activity::factory()->issue()->doing()->emergency('Incêndio antigo')->create();
    $next = Activity::factory()->issue()->todo()->create();

    // Call 1: demote the active emergency.
    SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $active->id,
        'service_class' => 'standard',
    ])->assertOk();

    // Call 2: classify the new one.
    SoloBoardServer::tool(UpdateIssueTool::class, [
        'issue_id' => $next->id,
        'service_class' => 'emergency',
        'emergency_reason' => 'Incêndio novo',
    ])->assertOk();

    expect($active->fresh()->service_class)->toBe(ServiceClass::Standard)
        ->and($next->fresh()->service_class)->toBe(ServiceClass::Emergency)
        ->and($next->fresh()->emergency_reason)->toBe('Incêndio novo');
});

test('create-task refuses an emergency with no motivo and accepts one with it', function () {
    SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Incêndio sem motivo',
        'service_class' => 'emergency',
    ])->assertHasErrors([EmergencyRequiresReasonException::MESSAGE]);

    SoloBoardServer::tool(CreateTaskTool::class, [
        'title' => 'Incêndio com motivo',
        'service_class' => 'emergency',
        'emergency_reason' => 'Produção fora do ar',
    ])->assertOk();

    expect(Activity::where('title', 'Incêndio sem motivo')->exists())->toBeFalse()
        ->and(Activity::where('title', 'Incêndio com motivo')->first()->emergency_reason)
        ->toBe('Produção fora do ar');
});
