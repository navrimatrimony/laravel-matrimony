<?php

namespace App\Services\Ocr;

/**
 * Converts HTML <table> biodata into "Label :- Value" lines so label-driven parsers work
 * (inline extraction assumes "शिक्षण :- …" style, not raw <td> cells).
 */
final class OcrHtmlTableFlattener
{
    /**
     * Flatten all <table> blocks to text lines; non-table HTML is strip-tagged and appended.
     */
    public static function flatten(string $html): string
    {
        if ($html === '' || stripos($html, '<') === false) {
            return $html;
        }

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (! preg_match('/<table\b/is', $html)) {
            return self::stripTagsPreserveNewlines($html);
        }

        $out = [];
        if (preg_match_all('/<table\b[^>]*>.*?<\/table>/is', $html, $tables)) {
            foreach ($tables[0] as $tbl) {
                $block = self::flattenSingleTable($tbl);
                if ($block !== '') {
                    $out[] = $block;
                }
            }
            $rest = preg_replace('/<table\b[^>]*>.*?<\/table>/is', '', $html) ?? '';
            $rest = trim(self::stripTagsPreserveNewlines($rest));
            if ($rest !== '') {
                $out[] = $rest;
            }
        } else {
            $out[] = self::stripTagsPreserveNewlines($html);
        }

        return trim(implode("\n", array_filter($out, static fn (string $s): bool => $s !== '')));
    }

    private static function flattenSingleTable(string $tableHtml): string
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><body>'.$tableHtml.'</body>';
        if (@$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD) === false) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            return self::legacyStripTable($tableHtml);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return self::legacyStripTable($tableHtml);
        }

        $lines = [];
        foreach ($body->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $child) {
                if (! $child instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if (! in_array($tag, ['td', 'th'], true)) {
                    continue;
                }
                $txt = self::innerText($child);
                if ($txt !== '') {
                    $cells[] = $txt;
                }
            }
            if ($cells === []) {
                continue;
            }
            $line = self::rowToLabelValueLine($cells);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $cells
     */
    private static function rowToLabelValueLine(array $cells): string
    {
        $n = count($cells);
        if ($n === 1) {
            return $cells[0];
        }
        // <td>Label</td><td>:-</td><td>Value…</td>
        if ($n >= 3 && self::isSeparatorCell($cells[1])) {
            $label = $cells[0];
            $value = trim(implode(' ', array_slice($cells, 2)));

            return $value === '' ? $label : $label.' :- '.$value;
        }
        if ($n >= 2) {
            $label = $cells[0];
            $value = trim(implode(' ', array_slice($cells, 1)));

            return $value === '' ? $label : $label.' :- '.$value;
        }

        return '';
    }

    private static function isSeparatorCell(string $c): bool
    {
        $t = trim($c);

        return $t !== '' && preg_match('/^:?\s*-+\s*$/u', $t) === 1;
    }

    private static function innerText(\DOMElement $el): string
    {
        $buf = '';
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $buf .= $child->data;
            } elseif ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'br') {
                    $buf .= "\n";
                } else {
                    $buf .= self::innerText($child);
                }
            }
        }
        $buf = preg_replace('/[ \t\x{00A0}]+/u', ' ', $buf) ?? $buf;
        $buf = preg_replace('/\s*\n\s*/u', "\n", $buf) ?? $buf;

        return trim($buf);
    }

    private static function stripTagsPreserveNewlines(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?? $html;
        $t = strip_tags($html);
        $t = preg_replace('/\h+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\n{3,}/u', "\n\n", $t) ?? $t;

        return trim($t);
    }

    /** Fallback when DOM parsing fails. */
    private static function legacyStripTable(string $tableHtml): string
    {
        $t = preg_replace('/<\/tr\s*>/iu', "\n", $tableHtml) ?? $tableHtml;
        $t = preg_replace('/<\/t[dh]\s*>/iu', ' ', $t) ?? $t;
        $t = preg_replace('/<br\s*\/?>/iu', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = preg_replace('/\h+/u', ' ', $t) ?? $t;

        return trim($t);
    }
}
