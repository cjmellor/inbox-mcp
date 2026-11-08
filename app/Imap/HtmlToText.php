<?php

namespace App\Imap;

use Illuminate\Support\Stringable;

class HtmlToText
{
    public static function convert(string $html): string
    {
        return str($html)
            ->replaceMatches('#<(script|style|head)\b[^>]*>.*?</\1>#is', '')
            ->replaceMatches('#<br\s*/?>#i', "\n")
            ->replaceMatches('#</(p|div|tr|li|h[1-6]|blockquote|table)>#i', "\n")
            ->replaceMatches('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 ($1)')
            ->stripTags()
            ->pipe(fn (Stringable $text) => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->replaceMatches('/[ \t\x{00A0}]+/u', ' ')
            ->replaceMatches('/ *\n */', "\n")
            ->replaceMatches('/\n{3,}/', "\n\n")
            ->trim()
            ->toString();
    }
}
