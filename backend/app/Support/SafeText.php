<?php

namespace App\Support;

/**
 * User-written text (posts, comments) is stored as plain text with light
 * Markdown (**bold**, *italic*, links). HTML tags are removed here; the
 * React app renders the Markdown itself, never as raw HTML.
 */
class SafeText
{
    public static function clean(?string $text): string
    {
        $text = strip_tags((string) $text);
        $text = preg_replace("/\r\n?/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text); // at most one blank line

        return trim($text);
    }
}
