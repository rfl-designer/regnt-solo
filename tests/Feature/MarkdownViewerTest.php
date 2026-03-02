<?php

use App\Support\Markdown;
use Livewire\Livewire;

test('markdown render converts markdown to html', function () {
    $html = Markdown::render('# Hello World');

    expect($html)->toContain('<h1>Hello World</h1>');
});

test('markdown render strips raw html', function () {
    $html = Markdown::render('<script>alert("xss")</script>');

    expect($html)->not->toContain('<script>');
});

test('markdown render blocks unsafe links', function () {
    $html = Markdown::render('[click](javascript:alert("xss"))');

    expect($html)->not->toContain('javascript:');
});

test('markdown excerpt returns plain text', function () {
    $excerpt = Markdown::excerpt('# Hello **World**');

    expect($excerpt)->not->toContain('<')
        ->and($excerpt)->toContain('Hello World');
});

test('markdown excerpt truncates to specified length', function () {
    $longContent = str_repeat('Lorem ipsum dolor sit amet. ', 20);
    $excerpt = Markdown::excerpt($longContent, 50);

    expect(strlen($excerpt))->toBeLessThanOrEqual(53); // 50 + "..."
});

test('markdown viewer component renders', function () {
    Livewire::test('markdown-viewer', ['content' => '# Test'])
        ->assertSee('Test')
        ->assertStatus(200);
});

test('markdown viewer component renders with title', function () {
    Livewire::test('markdown-viewer', ['content' => '# Test', 'title' => 'My Title'])
        ->assertSee('My Title')
        ->assertStatus(200);
});

test('markdown viewer component hides copy buttons when disabled', function () {
    Livewire::test('markdown-viewer', ['content' => '# Test', 'showCopyButtons' => false])
        ->assertDontSee('Copiar MD')
        ->assertDontSee('Copiar HTML')
        ->assertStatus(200);
});

test('markdown render handles code blocks', function () {
    $html = Markdown::render("```php\necho 'hello';\n```");

    expect($html)->toContain('<code');
});

test('markdown render handles lists', function () {
    $html = Markdown::render("- Item 1\n- Item 2\n- Item 3");

    expect($html)->toContain('<ul>')
        ->and($html)->toContain('<li>');
});

test('markdown render handles bold and italic', function () {
    $html = Markdown::render('**bold** and *italic*');

    expect($html)->toContain('<strong>bold</strong>')
        ->and($html)->toContain('<em>italic</em>');
});

test('markdown render handles links', function () {
    $html = Markdown::render('[Laravel](https://laravel.com)');

    expect($html)->toContain('href="https://laravel.com"')
        ->and($html)->toContain('Laravel');
});

test('markdown render supports html content saved by rich text editor', function () {
    $html = Markdown::render('<p>Essa é uma spec</p><p>#SPEC</p>');

    expect($html)->toContain('<p>Essa é uma spec</p>')
        ->and($html)->toContain('<p>#SPEC</p>');
});

test('markdown render strips unsafe attributes from rich text html', function () {
    $html = Markdown::render('<p onclick="alert(1)">Oi</p><a href="https://example.com" target="_blank">Link</a>');

    expect($html)->toContain('<p>Oi</p>')
        ->and($html)->toContain('<a>Link</a>')
        ->and($html)->not->toContain('onclick')
        ->and($html)->not->toContain('target=');
});
