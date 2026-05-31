<?php

namespace App\Services\Parsing;

/**
 * DOM-based extraction of Marathi biodata rows from HTML &lt;table&gt; transcripts.
 * Produces flat hints aligned with {@see \App\Services\BiodataParserService} merge methods (priority: HTML → separated → inline).
 */
final class HtmlMarathiBiodataTableExtractor
{
    public const STRUCTURED_MARKER = '_html_table_structured';

    /**
     * @return array<string, string>
     */
    public static function extract(string $html): array
    {
        if ($html === '' || stripos($html, '<table') === false) {
            return [];
        }
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (! preg_match('/<table\b/is', $html)) {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"><body>'.$html.'</body>';
        if (@$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD) === false) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            return [];
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return [];
        }

        $hints = [];
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
            [$label, $value] = self::splitLabelValueFromCells($cells);
            if ($label === '' || $value === '') {
                continue;
            }
            $hoSlot = self::mapHoroscopeGridLabelToSlot($label, $value);
            if ($hoSlot !== null) {
                $hints[$hoSlot] = $value;

                continue;
            }
            $slot = self::mapLabelToSlot($label, $value);
            if ($slot === null) {
                continue;
            }
            // Multiple भाऊ/बहिण rows: preserve each line (merge splits in BiodataParserService).
            if ($slot === 'sibling_brother_line' || $slot === 'sibling_sister_line') {
                $prev = $hints[$slot] ?? '';
                $hints[$slot] = $prev === '' ? $value : trim($prev."\n".$value);

                continue;
            }
            // Single source of truth: later rows override (no OCR-style concatenation).
            $hints[$slot] = $value;
        }

        if ($hints === []) {
            return [];
        }
        $hints[self::STRUCTURED_MARKER] = '1';

        return $hints;
    }

    /**
     * Horoscope legend rows (label → value cells). Ordered mapping avoids confusing headers (e.g. "स्वामी") with values.
     *
     * @return ('horoscope_nakshatra'|'horoscope_charan'|'horoscope_nadi'|'horoscope_yoni'|'horoscope_gan'|'horoscope_rashi'|'horoscope_swami'|'horoscope_varna'|'horoscope_vairavarga')|null
     */
    public static function mapHoroscopeGridLabelToSlot(string $label, string $value): ?string
    {
        $n = self::normalizeLabel($label);
        if ($n === '') {
            return null;
        }
        if ($n === 'वर्ण' && self::valueLooksLikeHoroscopeVarna($value)) {
            return 'horoscope_varna';
        }
        if ($n === 'वर्ण') {
            return null;
        }

        $rows = [
            ['horoscope_nakshatra', '/^नक्षत्र$/u'],
            ['horoscope_charan', '/^चरण$/u'],
            ['horoscope_nadi', '/^नाडी$/u'],
            ['horoscope_yoni', '/^योनी$/u'],
            ['horoscope_gan', '/^गण$/u'],
            ['horoscope_rashi', '/^(?:रास|राशी)$/u'],
            ['horoscope_swami', '/^स्वामी$/u'],
            ['horoscope_vairavarga', '/^वैरवर्ग$/u'],
        ];

        foreach ($rows as [$slot, $re]) {
            if (preg_match($re, $n) === 1) {
                return $slot;
            }
        }

        return null;
    }

    private static function valueLooksLikeHoroscopeVarna(string $value): bool
    {
        $t = trim($value);
        if ($t === '') {
            return false;
        }
        if (preg_match('/^(क्षत्रिय|क्षत्रीय|ब्राह्मण|वैश्य|शूद्र)/u', $t)) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $cells
     * @return array{0: string, 1: string}
     */
    private static function splitLabelValueFromCells(array $cells): array
    {
        $n = count($cells);
        if ($n === 1) {
            if (preg_match('/^(.+?)\s*[:\-]\s*(.+)$/u', $cells[0], $m)) {
                return [trim($m[1]), self::cleanValueCell($m[2])];
            }

            return [trim($cells[0]), ''];
        }
        if ($n >= 3 && self::isSeparatorCell($cells[1])) {
            $label = $cells[0];
            $value = self::cleanValueCell(implode("\n", array_slice($cells, 2)));

            return [trim($label), $value];
        }
        if ($n >= 2) {
            $label = $cells[0];
            $value = self::cleanValueCell(implode("\n", array_slice($cells, 1)));

            return [trim($label), $value];
        }

        return ['', ''];
    }

    private static function isSeparatorCell(string $c): bool
    {
        $t = trim($c);
        if ($t === '') {
            return false;
        }
        if ($t === ':-' || $t === '–' || $t === '—') {
            return true;
        }

        return preg_match('/^:?\s*[\-–—]{1,3}\s*$/u', $t) === 1;
    }

    private static function cleanValueCell(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\s*(?::-?\s*|[-–—]\s*)+/u', '', $value) ?? $value;

        return trim($value);
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

    private static function normalizeLabel(string $label): string
    {
        $t = trim($label);
        $t = preg_replace('/^\s*\d+[\.)]\s*/u', '', $t);
        $t = preg_replace('/\s*[:\-–—]+$/u', '', $t);

        return trim($t);
    }

    public static function isStructuredTableHints(?array $hints): bool
    {
        return is_array($hints) && ($hints[self::STRUCTURED_MARKER] ?? '') === '1';
    }

    private static function mapLabelToSlot(string $label, string $value): ?string
    {
        $n = self::normalizeLabel($label);
        if ($n === '') {
            return null;
        }

        if ($n === 'वर्ण' && self::valueLooksLikeHoroscopeVarna($value)) {
            return null;
        }

        $rows = [
            ['full_name', '/^(?:मुलीचे\s*नांव|मुलीचे\s*नाव|मुलाचे\s*नाव|मुलाचे\s*नांव|वधूचे\s*नाव|वधूचे\s*नांव)$/u'],
            ['full_name', '/^नाव$/u'],
            ['date_of_birth', '/^(?:जन्मदिनांक|जन्म\s*तारीख|जन्मतारीख|जन्म\s*दिनांक)$/u'],
            ['birth_place', '/^(?:जन्म\s*स्थळ|जन्मस्थळ|जन्म\s*ठिकाण)$/u'],
            ['birth_time', '/^(?:जन्मवेळ|जन्म\s*वेळ|जन्म\s*वार\s*व\s*वेळ|जन्मवार\s*व\s*वेळ)$/u'],
            ['height', '/^(?:उंची|ऊंची|Height)$/iu'],
            ['complexion', '/^(?:वर्ण)$/u'],
            ['blood_group', '/^(?:रक्तगट|रक्त\s*गट|रक्‍त\s*गट)$/u'],
            ['highest_education', '/^(?:शिक्षण|Education)$/iu'],
            ['occupation_raw', '/^(?:नोकरी|व्यवसाय|व्यवसाय\s*विषय|Profession)$/iu'],
            ['kuldaivat', '/^(?:कुलदैवत|कुल\s*दैवत|कुलस्वामी)$/u'],
            ['caste', '/^(?:जात|Caste)$/iu'],
            ['devak', '/^(?:देवक)$/u'],
            ['gotra', '/^(?:गोत्र)$/u'],
            ['father_name', '/^(?:वडिलांचे\s*नांव|वडिलांचे\s*नाव|वडिलाचे\s*नाव|मुलाचे\s*वडील)$/u'],
            ['mother_name', '/^(?:आईचे\s*नांव|आईचे\s*नाव|मुलाची\s*आई)$/u'],
            ['address_native', '/^(?:मुळ\s*पत्ता|मूळ\s*पत्ता|Native\s*Place)$/iu'],
            ['address_parents', '/^(?:वडिलांचा\s*पत्ता|पालकांचा\s*पत्ता|घरचा\s*पत्ता|मु\.?\s*पो\.?\s*पत्ता|Parents\s*(?:Home\s*)?Address)$/iu'],
            ['address_current', '/^(?:सध्याचा\s*पत्ता|Current\s*Address|पत्ता|Address)$/iu'],
            ['primary_contact', '/^(?:मोबाईल\s*नंबर|मोबाईल|मोबाइल|संपर्क\s*नंबर|Mobile|Phone)$/iu'],
            ['atya_note', '/^(?:आत्या|मुलाची\s*आत्या)$/u'],
            ['mama_note', '/^(?:मामा|मुलाचे\s*मामा)$/u'],
            ['ajol_note', '/^(?:आजोळ|आजोळ\s*\(\s*मामा\s*\))$/u'],
            ['sibling_brother_line', '/^भाऊ$/u'],
            ['sibling_sister_line', '/^(?:बहीण|बहिण)$/u'],
            ['other_relatives_text', '/^(?:इतर\s*नातेवाईक|इतर\s*पाहुणे)$/u'],
            ['property_summary', '/^(?:इतर\s*प्रॉपर्टी|स्थायिक\s*मालमत्ता|मालमत्ता)$/u'],
        ];

        foreach ($rows as [$slot, $re]) {
            if (preg_match($re, $n) === 1) {
                return $slot;
            }
        }

        return null;
    }
}
