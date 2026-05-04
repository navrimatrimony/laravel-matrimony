<?php

namespace App\Support;

use App\Models\Location;
use App\Models\MatrimonyProfile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Presentation-only copy for public profile views (formatting, not business rules).
 */
class ProfileDisplayCopy
{
    /**
     * Canonical residence line: SSOT {@code addresses} row via {@code location_id}, else legacy flat columns.
     */
    public static function profileResidenceDisplayLine(MatrimonyProfile $p): string
    {
        $p->loadMissing(['city', 'taluka', 'district', 'state', 'country']);

        if ($p->location_id && Schema::hasTable(Location::geoTable())) {
            $canonical = $p->residenceLocationDisplayLine();
            if ($canonical !== '') {
                return $canonical;
            }
        }

        return self::formatResidenceDisplay(
            $p->city?->name,
            $p->taluka?->name,
            $p->district?->name,
            $p->state?->name,
            $p->country?->name
        );
    }

    /**
     * One-line headline: education • occupation • location • marital (when present).
     */
    public static function headline(MatrimonyProfile $p): string
    {
        $p->loadMissing(['district', 'state', 'maritalStatus', 'profession']);
        $loc = self::profileResidenceDisplayLine($p);
        if ($loc === '') {
            $loc = self::compactLocationLine(
                null,
                $p->district?->name,
                $p->state?->name
            );
        }
        $occ = $p->occupation_title ?: ($p->profession?->name ?? '');
        $parts = array_filter([
            self::formatEducationPhrase($p->highest_education ?: null),
            $occ !== '' ? self::formatOccupationPhrase($occ) : null,
            $loc !== '' ? $loc : null,
            ($p->maritalStatus?->label ?? '') !== '' ? self::sentenceCaseLabel($p->maritalStatus->label) : null,
        ]);

        return implode(' • ', $parts);
    }

    /**
     * Short factual intro when narrative is absent (no personality claims).
     */
    public static function introSentence(MatrimonyProfile $p): ?string
    {
        $name = trim((string) ($p->full_name ?? ''));
        if ($name === '') {
            return null;
        }
        $name = self::formatPersonName($name);

        $p->loadMissing(['district', 'state', 'familyType', 'gender']);

        $edu = ($p->highest_education ?? '') !== ''
            ? self::formatEducationPhrase($p->highest_education)
            : null;
        $occ = ($p->occupation_title ?? '') !== ''
            ? self::formatOccupationPhrase($p->occupation_title)
            : null;
        $loc = self::profileResidenceDisplayLine($p);
        if ($loc === '') {
            $loc = self::compactLocationLine(
                null,
                $p->district?->name,
                $p->state?->name
            );
        }

        $familyClause = null;
        if ($p->familyType && ($p->familyType->label ?? '') !== '') {
            $ft = Str::lower(trim((string) $p->familyType->label));
            $article = preg_match('/^[aeiou]/i', $ft) ? 'an' : 'a';
            $familyClause = 'from '.$article.' '.$ft.' family';
        }

        if ($edu === null && $occ === null && $loc === '' && $familyClause === null) {
            return null;
        }

        $fam = $familyClause !== null ? ', '.$familyClause : '';

        if ($edu !== null && $occ !== null && $loc !== '') {
            $eduClause = self::educationClauseForIntro($edu);

            return "{$name} is {$eduClause} working as {$occ}, based in {$loc}{$fam}.";
        }

        if ($edu !== null && $occ !== null && $loc === '') {
            $eduClause = self::educationClauseForIntro($edu);

            return "{$name} is {$eduClause} working as {$occ}{$fam}.";
        }

        if ($edu !== null && $occ === null && $loc !== '') {
            $eduClause = self::educationClauseForIntro($edu);

            return "{$name} is {$eduClause}, based in {$loc}{$fam}.";
        }

        if ($edu === null && $occ !== null && $loc !== '') {
            return "{$name} works as {$occ}, based in {$loc}{$fam}.";
        }

        if ($edu !== null && $occ === null && $loc === '') {
            $eduClause = self::educationClauseForIntro($edu);

            return "{$name} is {$eduClause}{$fam}.";
        }

        if ($edu === null && $occ === null && $loc !== '') {
            return "{$name} is based in {$loc}{$fam}.";
        }

        if ($familyClause !== null) {
            return "{$name} comes {$familyClause}.";
        }

        return null;
    }

    /**
     * City, district, state — drop redundant district/city pairs and trailing "City".
     */
    public static function compactLocationLine(?string $city, ?string $district, ?string $state): string
    {
        $city = self::stripDisplayCitySuffix(trim((string) $city));
        $district = self::stripDisplayCitySuffix(trim((string) $district));
        $state = trim((string) $state);

        $norm = static function (string $s): string {
            $s = mb_strtolower(trim($s));
            $s = preg_replace('/\s+city$/u', '', $s) ?? $s;

            return trim($s);
        };

        if ($city !== '' && $district !== '') {
            $cn = $norm($city);
            $dn = $norm($district);
            if ($cn === $dn || str_contains($cn, $dn) || str_contains($dn, $cn)) {
                return trim(implode(', ', array_filter([$city, $state])));
            }
        }

        if ($city !== '' && $district !== '' && $state !== '') {
            return trim(implode(', ', array_filter([$city, $district, $state])));
        }

        return trim(implode(', ', array_filter([$city, $district, $state])));
    }

    /**
     * Residence / overview line with taluka and country when useful; reduces duplicate place names.
     */
    public static function formatResidenceDisplay(
        ?string $city,
        ?string $taluka,
        ?string $district,
        ?string $state,
        ?string $country
    ): string {
        $city = self::stripDisplayCitySuffix(trim((string) $city));
        $taluka = trim((string) $taluka);
        $district = self::stripDisplayCitySuffix(trim((string) $district));
        $state = trim((string) $state);
        $country = trim((string) $country);

        $norm = static function (string $s): string {
            $s = mb_strtolower(trim($s));
            $s = preg_replace('/\s+city$/u', '', $s) ?? $s;

            return trim($s);
        };

        $candidates = array_filter([$city, $taluka, $district, $state, $country]);
        $parts = [];
        $seen = [];
        foreach ($candidates as $p) {
            $n = $norm($p);
            if ($n === '' || isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $parts[] = $p;
        }

        return implode(', ', $parts);
    }

    public static function formatPersonName(string $name): string
    {
        return collect(preg_split('/\s+/u', trim($name)))
            ->filter()
            ->map(fn ($w) => Str::title(mb_strtolower($w)))
            ->implode(' ');
    }

    public static function formatEducationPhrase(?string $edu): ?string
    {
        if ($edu === null || trim($edu) === '') {
            return null;
        }
        $edu = trim($edu);
        if (preg_match('/^([a-z]{1,6})(\s+in\s+)(.+)$/i', $edu, $m)) {
            return mb_strtoupper($m[1]).' in '.Str::title(mb_strtolower($m[3]));
        }
        if (preg_match('/^[a-z][a-z0-9.\-]{0,10}$/i', $edu) && ! preg_match('/\s/', $edu)) {
            return mb_strtoupper($edu);
        }

        return Str::title(mb_strtolower($edu));
    }

    public static function formatOccupationPhrase(string $occ): string
    {
        return Str::title(mb_strtolower(trim($occ)));
    }

    public static function formatCompanyName(string $company): string
    {
        $c = trim($company);
        if ($c === '') {
            return $c;
        }
        if (preg_match('/\b(pvt|ltd|llp|inc|corp)\b/i', $c)) {
            return Str::title(mb_strtolower(preg_replace('/\s+/', ' ', $c)));
        }

        return Str::title(mb_strtolower($c));
    }

    private static function stripDisplayCitySuffix(string $city): string
    {
        if ($city === '') {
            return $city;
        }

        return trim(preg_replace('/\s+City$/iu', '', $city) ?? $city);
    }

    private static function educationClauseForIntro(string $eduFormatted): string
    {
        if (preg_match('/ph\.?\s*d|doctorate|doctor of philosophy/i', $eduFormatted)) {
            return 'a '.$eduFormatted.' holder';
        }

        $article = self::indefiniteArticleBeforeWord($eduFormatted);

        return $article.' '.$eduFormatted.' graduate';
    }

    private static function indefiniteArticleBeforeWord(string $word): string
    {
        if (preg_match('/^(?:MBA|LLB|LL\.M\.?|MCA|M\.Sc\.?|M\.S\.?|M\.Tech\.?|M\.E\.?|M\.A\.?|Hons\.?)/i', $word)) {
            return 'an';
        }
        if (preg_match('/^[aeiou]/i', $word)) {
            return 'an';
        }

        return 'a';
    }

    private static function sentenceCaseLabel(string $label): string
    {
        $l = trim($label);
        if ($l === '') {
            return $l;
        }

        return Str::lower($l) === $l ? Str::title($l) : $l;
    }
}
