<?php

namespace App\Support;

use Illuminate\Support\Str;

class Markdown
{
    /**
     * Render markdown content to safe HTML.
     */
    public static function render(string $content): string
    {
        if (static::looksLikeHtml($content)) {
            return static::sanitizeEditorHtml($content);
        }

        return Str::markdown($content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Get a plain-text excerpt from markdown content.
     */
    public static function excerpt(string $content, int $length = 200): string
    {
        $plain = strip_tags(static::render($content));

        return Str::limit($plain, $length);
    }

    private static function looksLikeHtml(string $content): bool
    {
        return preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $content) === 1;
    }

    private static function sanitizeEditorHtml(string $content): string
    {
        $withoutScripts = preg_replace('#<\s*(script|style)[^>]*>.*?<\s*/\s*\\1>#is', '', $content) ?? $content;
        $withoutAttributes = preg_replace('/<\s*([a-z0-9]+)\b[^>]*>/i', '<$1>', $withoutScripts) ?? $withoutScripts;

        return strip_tags(
            $withoutAttributes,
            '<p><br><strong><em><u><s><del><ul><ol><li><blockquote><pre><code><a><h1><h2><h3><h4><h5><h6><hr>'
        );
    }
}
