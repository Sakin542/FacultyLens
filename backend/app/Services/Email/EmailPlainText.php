<?php

namespace App\Services\Email;

/**
 * Derives the plain-text alternative from the rendered HTML so every template automatically ships a readable
 * fallback (links become "Label: URL", block elements become line breaks, everything else is stripped).
 */
final class EmailPlainText
{
    public static function fromHtml(string $html): string
    {
        $text = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        // hidden preheader / visually-hidden spans are not meant for the text version
        $text = preg_replace('/<[^>]+data-plain-text="skip"[^>]*>.*?<\/[a-z0-9]+>/is', '', $text) ?? $text;
        $text = preg_replace_callback('/<a\b[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', function ($m) {
            $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($label === '' || $label === $href) {
                return $href;
            }
            if (str_starts_with($href, 'mailto:')) {
                return $label;
            }

            return "{$label}: {$href}";
        }, $text) ?? $text;
        $text = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|tr|li|h[1-6]|table|blockquote|section)>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<\/(td|th)>/i', ' ', $text) ?? $text;
        $text = preg_replace('/<li\b[^>]*>/i', '- ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $lines = array_map(fn ($line) => trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line), preg_split('/\R/', $text) ?: []);
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text) . "\n";
    }
}
