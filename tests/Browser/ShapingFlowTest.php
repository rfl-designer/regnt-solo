<?php

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;

/**
 * Shaping in a real browser (issue #148): the walk from a raw Ideia on the
 * Ideias page to a bet placed in Backlog.
 *
 * The Feature suite already proves the component's contract from the server
 * side. What it cannot prove is the part the user actually touches: that the
 * `<flux:editor>` sections reach the column on blur, that the chips paint the
 * apetite and the aside answers, that the footer refuses in words, and that a
 * promotion carries the very same row over to the board. That is what lives
 * here.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/**
 * The contenteditable of a `<flux:editor>` inside one of the numbered
 * sections — the element the user types into, which is not the field
 * `wire:model` is written on.
 */
function shapingEditor(string $section): string
{
    return "#secao-{$section} [contenteditable='true']";
}

/**
 * The heading of a section — a place to click that takes focus away without
 * also operating a control, which is how `wire:model.live.blur` is made to
 * fire.
 */
function shapingHeading(string $section): string
{
    return "#secao-{$section} [data-flux-heading]";
}

/**
 * Listen for the payload the "Copiar pitch" button dispatches, so the copy
 * can be asserted without asking a headless browser for clipboard
 * permissions — the markdown handed to `navigator.clipboard` *is* the
 * behaviour under test.
 */
function captureCopiedPitchScript(): string
{
    return <<<'JS'
    (() => {
        window.__copiedPitch = null;
        window.addEventListener('copy-to-clipboard', e => { window.__copiedPitch = e.detail.markdown });

        return 'listening';
    })()
    JS;
}

/**
 * Which appetite chips are pressed, read off `aria-pressed` — the selection
 * is a visual claim and must not exist only in the colour.
 */
function pressedAppetiteChipsScript(): string
{
    return <<<'JS'
    (() => Array.from(document.querySelectorAll('[data-test^="appetite-chip-"]'))
        .filter(el => el.getAttribute('aria-pressed') === 'true')
        .map(el => el.getAttribute('data-test'))
        .join(','))()
    JS;
}

/**
 * Write into one of the `<flux:editor>` sections and look away.
 *
 * Typing rather than filling, because the editor is a rich-text control and
 * not an input: setting its value outright never reaches the document model,
 * so nothing would sync. This is the same sequence a person performs.
 */
function shapeSection(mixed $page, string $section, string $text): void
{
    $page->click(shapingEditor($section));
    $page->typeSlowly(shapingEditor($section), $text, 5);
    $page->click(shapingHeading($section));
}

test('a raw idea opens its shaping from Ideias with the five sections', function (): void {
    $draft = Activity::factory()->draft()->create(['title' => 'Fila de puxar sozinha']);

    $page = visit('/ideas')
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-test="shelf-raw"]')
        ->assertSee('Fila de puxar sozinha')
        ->assertSee('Dar forma');

    $page->click('Dar forma')
        ->waitForText('Dar forma antes de apostar')
        ->assertSee('Fila de puxar sozinha')
        ->assertSee('1 · Dor')
        ->assertSee('2 · Apetite')
        ->assertSee('3 · Esboço')
        ->assertSee('4 · Rabbit holes')
        ->assertSee('5 · No-gos')
        // Nothing is written yet, so the footer says what the gate wants.
        ->assertSeeIn('[data-test="promote-hint"]', 'Dor, Apetite, Esboço, Projeto')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.querySelectorAll(\'[data-test="progress-pips"] span\').length'))->toBe(5);
});

test('every section autosaves on blur and is still there after a reload', function (): void {
    $draft = Activity::factory()->draft()->create(['title' => 'Ideia que se salva sozinha']);

    $page = visit(route('shaping', $draft))->assertNoJavaScriptErrors();

    shapeSection($page, 'dor', 'Refaco isso a mao toda semana.');
    $page->click('[data-test="appetite-chip-7"]');
    shapeSection($page, 'esboco', 'Uma fila que se ordena sozinha.');

    $page->fill('[data-test="field-rabbit-holes"]', 'Ordenacao com empates.')
        ->click(shapingHeading('rabbit-holes'))
        ->fill('[data-test="field-no-gos"]', 'Nao mexe no Backlog.')
        ->click(shapingHeading('no-gos'));

    // The fifth pip lights only once the last blur has landed, so waiting for
    // the footer to say "5/5" is waiting for every autosave.
    $page->waitForText('5/5')->assertNoJavaScriptErrors();

    expect($draft->fresh())
        ->description->toContain('Refaco isso a mao toda semana.')
        ->spec->toContain('Uma fila que se ordena sozinha.')
        ->rabbit_holes->toBe('Ordenacao com empates.')
        ->no_gos->toBe('Nao mexe no Backlog.')
        ->appetite_days->toBe(7);

    // And the page the user comes back to is the page they left.
    visit(route('shaping', $draft))
        ->assertNoJavaScriptErrors()
        ->assertSee('Refaco isso a mao toda semana.')
        ->assertSee('Uma fila que se ordena sozinha.')
        ->assertSee('5/5');
});

test('a chip paints the apetite and the aside says there is no history yet', function (): void {
    $draft = Activity::factory()->draft()->create(['title' => 'Ideia com apetite']);

    $page = visit(route('shaping', $draft))
        ->assertNoJavaScriptErrors()
        ->assertSeeIn('[data-test="appetite-history"]', 'Seu histórico')
        ->assertSeeIn('[data-test="appetite-history"]', 'Ainda sem histórico');

    $page->click('[data-test="appetite-chip-14"]')
        ->waitForText('1/5')
        ->assertNoJavaScriptErrors();

    expect($draft->fresh()->appetite_days)->toBe(14);
    expect($page->script(pressedAppetiteChipsScript()))->toBe('appetite-chip-14');

    // Under three validated Specs the aside refuses to quote a percentile at
    // all — it is a mirror, and there is nothing yet to mirror.
    $page->assertSeeIn('[data-test="appetite-history"]', 'nenhuma spec validada desde o corte')
        ->assertDontSeeIn('[data-test="appetite-history"]', 'cobre');
});

test('promoting an incomplete idea is refused in words and changes nothing', function (): void {
    $draft = Activity::factory()->draft()->create(['title' => 'Ideia pela metade']);

    $page = visit(route('shaping', $draft))->assertNoJavaScriptErrors();

    shapeSection($page, 'dor', 'So a dor esta escrita.');
    $page->waitForText('1/5');

    $page->click('[data-test="promote"]')
        ->waitForText('Ainda não dá para promover')
        // The refusal names exactly what is missing.
        ->assertSee('Apetite')
        ->assertSee('Esboço')
        ->assertSee('Projeto')
        ->assertNoJavaScriptErrors();

    expect($draft->fresh())
        ->type->toBe(ActivityType::Draft)
        ->status->toBeNull();
});

test('a shaped idea becomes the very same record as an Épico in Backlog', function (): void {
    $project = Project::factory()->create(['name' => 'Projeto da aposta']);
    $draft = Activity::factory()->draft()->shaped()->create(['title' => 'Aposta pronta para nascer']);

    $page = visit(route('shaping', $draft))
        ->assertNoJavaScriptErrors()
        ->assertSee('3/5');

    // The project is the fourth requirement, and the only one still open.
    $page->assertSeeIn('[data-test="promote-hint"]', 'Projeto')
        ->select('[data-test="project-select"]', (string) $project->id)
        // The hint retries until the re-render lands, so its absence is the
        // gate reporting that nothing is missing anymore.
        ->assertMissing('[data-test="promote-hint"]')
        ->assertNoJavaScriptErrors();

    $page->click('[data-test="promote"]')
        ->waitForText('Épico criado')
        ->assertNoJavaScriptErrors();

    // Promotion is in place: same id, new type, born in Backlog with the
    // shaping intact.
    expect($draft->fresh())
        ->type->toBe(ActivityType::Epic)
        ->status->toBe(ActivityStatus::Backlog)
        ->project_id->toBe($project->id)
        ->appetite_days->toBe(7)
        ->description->not->toBeNull()
        ->spec->not->toBeNull();

    // And it is on the board where the user was sent.
    $page->waitForText('Aposta pronta para nascer')
        ->assertNoJavaScriptErrors();

    // The Ideia is gone from Ideias, because it is no longer an Ideia.
    visit('/ideas')
        ->assertNoJavaScriptErrors()
        ->assertDontSee('Aposta pronta para nascer');
});

test('"Talvez mais adiante" keeps the partial and Ideias offers it back', function (): void {
    $draft = Activity::factory()->draft()->create(['title' => 'Ideia que fica para depois']);

    $page = visit(route('shaping', $draft))->assertNoJavaScriptErrors();

    shapeSection($page, 'dor', 'A dor ja esta escrita.');
    $page->waitForText('1/5');

    $page->click('[data-test="appetite-chip-3"]')
        ->waitForText('2/5')
        ->assertNoJavaScriptErrors();

    // Leaving costs nothing: there is nothing to discard and nothing to
    // confirm, because everything already wrote itself.
    $page->click('[data-test="maybe-later"]')
        ->waitForText('Ideias')
        ->assertPresent('[data-test="shelf-shaped"]')
        ->assertSeeIn('[data-test="shelf-shaped"]', 'Ideia que fica para depois')
        ->assertSeeIn('[data-test="shelf-shaped"]', '2/5')
        ->assertSeeIn('[data-test="shelf-shaped"]', '3d')
        ->assertSeeIn('[data-test="shelf-shaped"]', 'Retomar shaping')
        ->assertNoJavaScriptErrors();

    expect($draft->fresh())
        ->type->toBe(ActivityType::Draft)
        ->appetite_days->toBe(3)
        ->description->toContain('A dor ja esta escrita.');

    // And it goes back to the same page, with the same content.
    $page->click('Retomar shaping')
        ->waitForText('Dar forma antes de apostar')
        ->assertSee('A dor ja esta escrita.')
        ->assertSee('2/5')
        ->assertNoJavaScriptErrors();
});

test('"Copiar pitch" copies the pitch of what is on screen', function (): void {
    $draft = Activity::factory()->draft()->create(['title' => 'Ideia para colar em conversa']);

    $page = visit(route('shaping', $draft))
        ->assertNoJavaScriptErrors()
        ->assertPresent('[data-test="copy-pitch"]');

    $page->script(captureCopiedPitchScript());

    shapeSection($page, 'dor', 'Dor recem escrita.');
    $page->waitForText('1/5');

    $page->click('[data-test="copy-pitch"]')
        ->waitForText('Copiado!')
        ->assertNoJavaScriptErrors();

    // Every section, in order, including the empty ones — the pitch is
    // deterministic, and an empty "No-gos" is itself worth reading.
    expect($page->script('window.__copiedPitch'))
        ->toContain('# Ideia para colar em conversa')
        ->toContain('## Dor')
        ->toContain('Dor recem escrita.')
        ->toContain('## Apetite')
        ->toContain('## Esboço')
        ->toContain('## Rabbit holes')
        ->toContain('## No-gos')
        ->toContain('_(vazio)_');
});
