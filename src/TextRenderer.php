<?php

declare(strict_types=1);

namespace Yatmo;

use Yatmo\Model\SummaryText;
use Yatmo\Model\TextParagraph;

/**
 * Renders a neighbourhood text as HTML (headings and paragraphs to write into a page so search
 * engines index it), Markdown or plain text.
 *
 * Options: `heading` (h2 to h6, default h3, null for no titles), `titles` (`street-city` default:
 * street in the first title and city in the second; `city`: never names the street; `generic`),
 * `paragraphs` (only these types, in this order), `strong` (key places in bold, default true),
 * `class` (extra class on the wrapper).
 */
final class TextRenderer
{
    public const STRONG_OPEN = '[STRONG]';
    public const STRONG_CLOSE = '[/STRONG]';

    /** @param array{heading?: ?string, titles?: string, paragraphs?: string[], strong?: bool, class?: string} $options */
    public static function html(SummaryText $text, array $options = []): string
    {
        $heading = \array_key_exists('heading', $options) ? $options['heading'] : 'h3';
        $strong = $options['strong'] ?? true;
        $parts = [];
        foreach (self::select($text, $options['paragraphs'] ?? null) as $index => $paragraph) {
            $html = '';
            if ($heading !== null && $heading !== '') {
                $html .= sprintf('<%1$s>%2$s</%1$s>', $heading, self::escape(self::title($paragraph, $index, $options['titles'] ?? 'street-city')));
            }
            if ($paragraph->sentences !== []) {
                $html .= '<p>' . implode(' ', array_map(fn (string $s) => self::sentenceToHtml($s, $strong), $paragraph->sentences)) . '</p>';
            }
            if ($paragraph->items !== []) {
                $html .= '<ul>' . implode('', array_map(fn (string $s) => '<li>' . self::sentenceToHtml($s, $strong) . '</li>', $paragraph->items)) . '</ul>';
            }
            $parts[] = $html;
        }
        $class = trim('yatmo-text ' . ($options['class'] ?? ''));

        return '<div class="' . self::escape($class) . '">' . implode('', $parts) . '</div>';
    }

    /** @param array{titles?: string, paragraphs?: string[], strong?: bool} $options */
    public static function markdown(SummaryText $text, array $options = []): string
    {
        $strong = $options['strong'] ?? true;
        $blocks = [];
        foreach (self::select($text, $options['paragraphs'] ?? null) as $index => $paragraph) {
            $lines = ['### ' . self::title($paragraph, $index, $options['titles'] ?? 'street-city'), ''];
            if ($paragraph->sentences !== []) {
                $lines[] = implode(' ', array_map(fn (string $s) => self::sentenceToMarkdown($s, $strong), $paragraph->sentences));
            }
            foreach ($paragraph->items as $item) {
                $lines[] = '- ' . self::sentenceToMarkdown($item, $strong);
            }
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /** @param array{titles?: string, paragraphs?: string[]} $options */
    public static function plain(SummaryText $text, array $options = []): string
    {
        $blocks = [];
        foreach (self::select($text, $options['paragraphs'] ?? null) as $index => $paragraph) {
            $lines = [self::title($paragraph, $index, $options['titles'] ?? 'street-city')];
            foreach ($paragraph->sentences as $s) {
                $lines[] = self::stripMarkers($s);
            }
            foreach ($paragraph->items as $item) {
                $lines[] = '- ' . self::stripMarkers($item);
            }
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /** Escapes a sentence and turns the markers into `<strong>`. */
    public static function sentenceToHtml(string $sentence, bool $strong = true): string
    {
        $escaped = self::escape(trim($sentence));

        return $strong
            ? str_replace([self::STRONG_OPEN, self::STRONG_CLOSE], ['<strong>', '</strong>'], $escaped)
            : self::stripMarkers($escaped);
    }

    public static function stripMarkers(string $text): string
    {
        return str_replace([self::STRONG_OPEN, self::STRONG_CLOSE], '', $text);
    }

    private static function sentenceToMarkdown(string $sentence, bool $strong): string
    {
        $trimmed = trim($sentence);

        return $strong ? str_replace([self::STRONG_OPEN, self::STRONG_CLOSE], '**', $trimmed) : self::stripMarkers($trimmed);
    }

    private static function title(TextParagraph $paragraph, int $index, string $mode): string
    {
        if ($mode === 'generic') {
            return $paragraph->title;
        }
        if ($mode === 'city') {
            return ($index === 0 && $paragraph->titleCity) ? $paragraph->titleCity : $paragraph->title;
        }
        if ($index === 0 && $paragraph->titleStreet) {
            return $paragraph->titleStreet;
        }
        if ($index === 1 && $paragraph->titleCity) {
            return $paragraph->titleCity;
        }

        return $paragraph->title;
    }

    /**
     * @param string[]|null $wanted
     * @return TextParagraph[]
     */
    private static function select(SummaryText $text, ?array $wanted): array
    {
        if ($wanted === null || $wanted === []) {
            return array_values($text->paragraphs);
        }
        $lower = array_map('strtolower', $wanted);

        return array_values(array_filter($text->paragraphs, fn (TextParagraph $p) => \in_array(strtolower($p->iconId), $lower, true)));
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
