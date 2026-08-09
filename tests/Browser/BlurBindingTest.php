<?php

use App\Models\Document;
use App\Models\User;
use App\Models\WeeklyReview;
use Carbon\Carbon;

/**
 * The two fields outside shaping that were bound with a bare
 * `wire:model.blur`.
 *
 * Since Livewire 4.1 that modifier only syncs the value inside the browser —
 * no request is sent — so a field whose whole purpose is a server-side effect
 * on blur silently had none. Both pages claim otherwise on screen: the weekly
 * review says "Salvo automaticamente", and the document form renders the slug
 * it derives from the title. These tests assert the claim.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('the weekly reflection saves itself when the user looks away', function (): void {
    $review = WeeklyReview::getOrCreateForWeek(Carbon::now());

    $page = visit('/review')->assertNoJavaScriptErrors();

    $page->fill('[data-test="weekly-reflection"]', 'Semana boa: entreguei duas fatias.')
        ->keys('[data-test="weekly-reflection"]', 'Tab')
        ->assertSee('Salvo automaticamente')
        ->assertNoJavaScriptErrors();

    expect($review->fresh()->reflection)->toBe('Semana boa: entreguei duas fatias.');

    // And it is still there on the way back, which is the whole promise.
    // Read off the field's value rather than the page's text: a textarea's
    // content is not something `assertSee` can see.
    $reopened = visit('/review')->assertNoJavaScriptErrors();

    expect($reopened->script('document.querySelector(\'[data-test="weekly-reflection"]\').value'))
        ->toBe('Semana boa: entreguei duas fatias.');
});

test('the document title feeds the slug preview when the user looks away', function (): void {
    $document = Document::factory()->create(['title' => 'Titulo antigo']);

    $page = visit(route('document.edit', $document->slug))
        ->assertNoJavaScriptErrors()
        ->assertSee('Slug: '.$document->slug);

    $page->fill('[data-test="document-title"]', 'Guia de operacao')
        ->keys('[data-test="document-title"]', 'Tab')
        // The preview is computed on the server, so seeing it change is
        // seeing the blur reach the component.
        ->assertSee('Slug: guia-de-operacao')
        ->assertNoJavaScriptErrors();
});
