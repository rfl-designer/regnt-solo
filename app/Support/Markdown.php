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
}
