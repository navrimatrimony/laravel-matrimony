<?php

namespace App\Services;

use App\Models\EducationDegree;
use App\Models\MatrimonyProfile;
use App\Support\MasterData\MasterDataAliasNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for education degree search, normalization, resolution, and display.
 * Uses {@see EducationDegree} / education_categories only (no duplicate educations master).
 *
 * PART 0 — SSOT / tables (read-only audit; never drop tables from here):
 * - `education_degrees` + `education_categories`: ACTIVE (includes optional `name_mr`, `code_mr`, `title_mr`, `full_form_mr`). Used by EducationService search/ranking,
 *   {@see \App\Http\Controllers\Api\EducationDegreeSearchController}, onboarding step 4 (persists to `highest_education` text).
 * - `educations`: LEGACY duplicate master (if still present in DB). Not queried by EducationService or
 *   `/api/education-degrees/search`. Cleanup is manual / separate Artisan command only.
 */
class EducationService
{
    /**
     * Legacy free-text labels → canonical degree title/code hints before DB matching.
     * Keys: {@see legacyLabelMapKey()} (alphanumeric only, lowercase).
     *
     * @var array<string, string>
     */
    private const LEGACY_LABEL_ALIASES = [
        'graduate' => 'B.A',
        'postgraduate' => 'MBA',
        'ssc' => '10th',
        'hsc' => '12th',
        '12thhsc' => '12th',
        'belowssc' => 'Below 10th',
        'professional' => 'Diploma',
        'bachelorcommerce' => 'B.Com',
        'bachelorarts' => 'B.A',
        'bachelorcomputerapplications' => 'BCA',
    ];

    /**
     * Normalize for matching: lowercase, strip dots and whitespace (spec).
     */
    public function normalize(string $input): string
    {
        $s = mb_strtolower(trim($input));
        $s = str_replace(['.', "\xc2\xa0"], '', $s);
        $s = preg_replace('/\s+/u', '', $s);

        return $s;
    }

    /**
     * Stable key for legacy alias lookup (ASCII letters/digits only, lowercased).
     */
    public function legacyLabelMapKey(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value));
    }

    /**
     * Map known legacy labels (e.g. "Graduate", "Bachelor – Commerce") to a degree hint string.
     * Does not touch the DB; unknown values pass through unchanged.
     */
    public function applyLegacyLabelAlias(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $key = $this->legacyLabelMapKey($value);
        if ($key !== '' && isset(self::LEGACY_LABEL_ALIASES[$key])) {
            return self::LEGACY_LABEL_ALIASES[$key];
        }

        return $value;
    }

    public function findDegreeMatch(string $input): ?EducationDegree
    {
        $effective = $this->applyLegacyLabelAlias(trim($input));
        if ($effective === '') {
            return null;
        }

        if (Schema::hasTable('education_degree_aliases')) {
            foreach (MasterDataAliasNormalizer::normalizedLookupCandidates($effective) as $norm) {
                if ($norm === '') {
                    continue;
                }
                $degreeId = DB::table('education_degree_aliases')
                    ->where('is_active', true)
                    ->where('normalized_alias', $norm)
                    ->value('education_degree_id');
                if ($degreeId !== null) {
                    $byAlias = EducationDegree::query()->with('category')->find((int) $degreeId);
                    if ($byAlias !== null) {
                        return $byAlias;
                    }
                }
            }
        }

        $key = $this->normalize($effective);
        if ($key === '') {
            return null;
        }

        $qLower = mb_strtolower($effective);

        $candidates = EducationDegree::query()
            ->with('category')
            ->where(function ($sub) use ($key, $qLower) {
                $sub->whereRaw('REPLACE(REPLACE(LOWER(code), \'.\', \'\'), \' \', \'\') = ?', [$key])
                    ->orWhereRaw('REPLACE(REPLACE(LOWER(title), \'.\', \'\'), \' \', \'\') = ?', [$key])
                    ->orWhereRaw('REPLACE(REPLACE(LOWER(COALESCE(code_mr, \'\')), \'.\', \'\'), \' \', \'\') = ?', [$key])
                    ->orWhereRaw('REPLACE(REPLACE(LOWER(COALESCE(title_mr, \'\')), \'.\', \'\'), \' \', \'\') = ?', [$key])
                    ->orWhereRaw('LOWER(code) LIKE ?', ['%'.$qLower.'%'])
                    ->orWhereRaw('LOWER(title) LIKE ?', ['%'.$qLower.'%'])
                    ->orWhere('code_mr', 'like', '%'.$qLower.'%')
                    ->orWhere('title_mr', 'like', '%'.$qLower.'%');
            })
            ->orderByRaw('LENGTH(code)')
            ->orderBy('title')
            ->limit(40)
            ->get();

        foreach ($candidates as $d) {
            if ($this->normalize((string) $d->code) === $key || $this->normalize((string) $d->title) === $key) {
                return $d;
            }
            $cm = (string) ($d->code_mr ?? '');
            $tm = (string) ($d->title_mr ?? '');
            if ($cm !== '' && $this->normalize($cm) === $key) {
                return $d;
            }
            if ($tm !== '' && $this->normalize($tm) === $key) {
                return $d;
            }
        }

        return $candidates->first();
    }

    /**
     * Normalize a string for substring search (alphanumeric only, lowercased).
     * Matches user intent: "ba" → finds "B.A", "BA (Hons)", "Bachelor".
     */
    public function normalizeSearchToken(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value));
    }

    /**
     * Search master degrees only (for API / dropdown). Never returns user-entered strings.
     *
     * @return Collection<int, EducationDegree>
     */
    public function searchDegrees(string $query, int $limit = 40): Collection
    {
        return $this->searchDegreesWithSuggestion($query, $limit)['degrees'];
    }

    /**
     * Ranked search + optional "did you mean" (Levenshtein ≤ 2 on normalized title/code).
     *
     * @return array{degrees: Collection<int, EducationDegree>, suggestion: ?string}
     */
    public function searchDegreesWithSuggestion(string $query, int $limit = 40): array
    {
        $q = trim($query);
        $columns = ['id', 'title', 'code', 'category_id'];

        if ($q === '') {
            return [
                'degrees' => EducationDegree::query()
                    ->with('category')
                    ->orderBy('title')
                    ->limit($limit)
                    ->get($columns),
                'suggestion' => null,
            ];
        }

        $qNorm = $this->normalizeSearchToken($q);
        $safeLowerLike = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
        $normLike = '%'.addcslashes($qNorm, '%_\\').'%';
        $rawLike = '%'.addcslashes($q, '%_\\').'%';

        if ($qNorm === '') {
            $candidates = EducationDegree::query()
                ->with('category')
                ->select($columns)
                ->where(function ($w) use ($rawLike) {
                    $w->where('title', 'like', $rawLike)
                        ->orWhere('code', 'like', $rawLike)
                        ->orWhere('full_form', 'like', $rawLike)
                        ->orWhere('title_mr', 'like', $rawLike)
                        ->orWhere('code_mr', 'like', $rawLike)
                        ->orWhere('full_form_mr', 'like', $rawLike);
                })
                ->limit(100)
                ->get();

            return [
                'degrees' => $candidates->take($limit)->values(),
                'suggestion' => null,
            ];
        }

        $candidates = EducationDegree::query()
            ->with('category')
            ->select($columns)
            ->where(function ($w) use ($safeLowerLike, $normLike, $rawLike) {
                $w->whereRaw('LOWER(title) LIKE ?', [$safeLowerLike])
                    ->orWhereRaw('LOWER(code) LIKE ?', [$safeLowerLike])
                    ->orWhereRaw("REPLACE(REPLACE(LOWER(title), '.', ''), ' ', '') LIKE ?", [$normLike])
                    ->orWhereRaw("REPLACE(REPLACE(LOWER(code), '.', ''), ' ', '') LIKE ?", [$normLike])
                    ->orWhere('title_mr', 'like', $rawLike)
                    ->orWhere('code_mr', 'like', $rawLike)
                    ->orWhere('full_form_mr', 'like', $rawLike)
                    ->orWhere('full_form', 'like', $rawLike);
            })
            ->limit(100)
            ->get();

        $scored = [];
        foreach ($candidates as $degree) {
            $titleNorm = $this->normalizeSearchToken((string) ($degree->title ?? ''));
            $codeNorm = $this->normalizeSearchToken((string) ($degree->code ?? ''));
            $titleMrNorm = $this->normalizeSearchToken((string) ($degree->title_mr ?? ''));
            $codeMrNorm = $this->normalizeSearchToken((string) ($degree->code_mr ?? ''));
            $score = $this->degreeSearchScore($titleNorm, $codeNorm, $qNorm);
            if ($score === null) {
                $score = $this->degreeSearchScore($titleMrNorm, $codeMrNorm, $qNorm);
            }
            if ($score === null) {
                continue;
            }
            $scored[] = ['degree' => $degree, 'score' => $score];
        }

        usort($scored, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return mb_strlen((string) $a['degree']->title) <=> mb_strlen((string) $b['degree']->title);
        });

        $degrees = collect($scored)->map(fn (array $row) => $row['degree'])->take($limit)->values();

        $topScore = $scored[0]['score'] ?? 0;
        $suggestion = $this->didYouMeanSuggestion($qNorm, $topScore, $degrees);

        return [
            'degrees' => $degrees,
            'suggestion' => $suggestion,
        ];
    }

    /**
     * @return int|null null = exclude row
     */
    private function degreeSearchScore(string $titleNorm, string $codeNorm, string $qNorm): ?int
    {
        $hasTitle = str_contains($titleNorm, $qNorm);
        $hasCode = str_contains($codeNorm, $qNorm);
        if (! $hasTitle && ! $hasCode) {
            return null;
        }
        if ($titleNorm === $qNorm) {
            return 100;
        }
        if (str_starts_with($titleNorm, $qNorm)) {
            return 70;
        }
        if (str_starts_with($codeNorm, $qNorm)) {
            return 40;
        }
        if ($hasTitle) {
            return 20;
        }

        return 10;
    }

    private function didYouMeanSuggestion(string $qNorm, int $topScore, Collection $rankedDegrees): ?string
    {
        if (mb_strlen($qNorm) < 2) {
            return null;
        }
        if ($topScore >= 100) {
            return null;
        }
        if ($rankedDegrees->isNotEmpty()) {
            return null;
        }

        $pool = EducationDegree::query()
            ->select(['title', 'code'])
            ->limit(500)
            ->get();

        $bestTitle = null;
        $bestDist = PHP_INT_MAX;

        foreach ($pool as $row) {
            foreach ([(string) $row->title, (string) $row->code] as $raw) {
                $tn = $this->normalizeSearchToken($raw);
                if ($tn === '' || $tn === $qNorm) {
                    continue;
                }
                if (strlen($qNorm) > 255 || strlen($tn) > 255) {
                    continue;
                }
                $dist = levenshtein($qNorm, $tn);
                if ($dist <= 2 && $dist < $bestDist) {
                    $bestDist = $dist;
                    $bestTitle = (string) $row->title;
                }
            }
        }

        return $bestTitle;
    }

    /**
     * Convert nested form name prefix like {@code snapshot[core]} to dot notation ({@code snapshot.core}) for {@see data_get()}.
     */
    public static function formNamePrefixToDot(?string $namePrefix): ?string
    {
        if ($namePrefix === null || $namePrefix === '') {
            return null;
        }

        $parts = preg_split('/[\[\]]+/', $namePrefix, -1, PREG_SPLIT_NO_EMPTY);

        return $parts !== [] ? implode('.', $parts) : null;
    }

    /**
     * Reads Tom Select multi education payload (ordered {@code education_slots} JSON + legacy parallel arrays),
     * and merges into {@code highest_education} / {@code highest_education_other} on the root request.
     *
     * @param  string|null  $dotPrefix  e.g. {@code snapshot.core}; {@code null} for flat onboarding/wizard names.
     * @return bool True when multiselect payload was present and merged (including explicit empty selection).
     */
    public function mergeMultiselectEducationIntoRequest(Request $request, ?string $dotPrefix = null): bool
    {
        if (! Schema::hasColumn('matrimony_profiles', 'highest_education')) {
            return false;
        }

        $pathSlots = $dotPrefix ? "{$dotPrefix}.education_slots" : 'education_slots';
        $pathIds = $dotPrefix ? "{$dotPrefix}.education_degree_ids" : 'education_degree_ids';
        $pathCustom = $dotPrefix ? "{$dotPrefix}.education_custom" : 'education_custom';

        $all = $request->all();
        $slotsRaw = data_get($all, $pathSlots);

        /** @var list<array{t: string, id?: int, x?: string}>|null $slots */
        $slots = null;

        if (Arr::has($all, $pathSlots)) {
            if ($slotsRaw === null || $slotsRaw === '') {
                $slots = [];
            } elseif (is_string($slotsRaw)) {
                $decoded = json_decode($slotsRaw, true);
                if (! is_array($decoded)) {
                    return false;
                }
                $slots = $this->normalizeEducationSlotsPayload($decoded);
            } elseif (is_array($slotsRaw)) {
                $slots = $this->normalizeEducationSlotsPayload($slotsRaw);
            } else {
                return false;
            }
        }

        if ($slots === null) {
            $idsRaw = data_get($all, $pathIds);
            $customsRaw = data_get($all, $pathCustom);
            $hasLegacy = Arr::has($all, $pathIds) || Arr::has($all, $pathCustom);
            if (! $hasLegacy) {
                return false;
            }
            $ids = is_array($idsRaw) ? $idsRaw : [];
            $customs = is_array($customsRaw) ? $customsRaw : [];
            $ids = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $ids), static fn ($v) => $v > 0)));
            $customs = array_values(array_filter(array_map(static fn ($s) => trim((string) $s), $customs), static fn ($s) => $s !== ''));
            if ($ids === [] && $customs === []) {
                return false;
            }
            // Legacy parallel arrays only preserve order within degrees, then within customs (not interleaved).
            $slots = [];
            foreach ($ids as $id) {
                $slots[] = ['t' => 'd', 'id' => $id];
            }
            foreach ($customs as $c) {
                $slots[] = ['t' => 'c', 'x' => $c];
            }
        }

        if ($slots === null) {
            return false;
        }

        if ($slots === []) {
            $request->merge([
                'highest_education' => null,
                'highest_education_other' => null,
            ]);

            return true;
        }

        $degreeIdsForTitles = [];
        foreach ($slots as $slot) {
            if (($slot['t'] ?? '') === 'd' && ! empty($slot['id'])) {
                $degreeIdsForTitles[] = (int) $slot['id'];
            }
        }
        $degreeIdsForTitles = array_values(array_unique(array_filter($degreeIdsForTitles, static fn ($id) => $id > 0)));

        $titlesById = [];
        if ($degreeIdsForTitles !== []) {
            $titlesById = EducationDegree::query()
                ->whereIn('id', $degreeIdsForTitles)
                ->pluck('title', 'id')
                ->all();
        }

        $primaryDegreeId = null;
        foreach ($slots as $slot) {
            if (($slot['t'] ?? '') === 'd' && ! empty($slot['id'])) {
                $primaryDegreeId = (int) $slot['id'];
                break;
            }
        }

        $displayParts = [];
        $textParts = [];

        foreach ($slots as $slot) {
            $t = $slot['t'] ?? '';
            if ($t === 'd' && ! empty($slot['id'])) {
                $did = (int) $slot['id'];
                $title = trim((string) ($titlesById[$did] ?? ''));
                if ($title === '') {
                    $deg = EducationDegree::query()->find($did);
                    $title = trim((string) ($deg ? ($deg->title ?: $deg->code) : ''));
                }
                if ($title !== '') {
                    $displayParts[] = $title;
                }
                if ($primaryDegreeId !== null && $did !== $primaryDegreeId && $title !== '') {
                    $textParts[] = $title;
                }
            } elseif ($t === 'c') {
                $txt = mb_substr(trim((string) ($slot['x'] ?? '')), 0, 512);
                if ($txt !== '') {
                    $displayParts[] = $txt;
                    $textParts[] = $txt;
                }
            }
        }

        $educationText = $textParts !== [] ? implode(', ', $textParts) : null;
        if ($educationText !== null) {
            $educationText = mb_substr($educationText, 0, 512);
        }

        $legacyLine = $displayParts !== [] ? mb_substr(implode(', ', $displayParts), 0, 255) : null;

        if ($primaryDegreeId === null) {
            $resolved = $this->resolveDegreeSelection(null, $educationText ?? '');
            $line = $resolved['legacy_highest_education'];
            if (($line === null || $line === '') && ! empty($resolved['education_text'])) {
                $line = mb_substr((string) $resolved['education_text'], 0, 255);
            }
            $request->merge([
                'highest_education' => $line,
                'highest_education_other' => null,
            ]);

            return true;
        }

        $primaryTitle = trim((string) ($titlesById[$primaryDegreeId] ?? ''));
        if ($primaryTitle === '') {
            $deg = EducationDegree::query()->find($primaryDegreeId);
            $primaryTitle = trim((string) ($deg ? ($deg->title ?: $deg->code) : ''));
        }

        $request->merge([
            'highest_education' => $legacyLine ?: $primaryTitle,
            'highest_education_other' => null,
        ]);

        return true;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<array{t: string, id?: int, x?: string}>
     */
    private function normalizeEducationSlotsPayload(array $decoded): array
    {
        $out = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $t = $row['t'] ?? $row['type'] ?? '';
            $t = $t === 'degree' ? 'd' : $t;
            $t = $t === 'custom' ? 'c' : $t;
            if ($t === 'd') {
                $id = isset($row['id']) ? (int) $row['id'] : 0;
                if ($id > 0) {
                    $out[] = ['t' => 'd', 'id' => $id];
                }
            } elseif ($t === 'c') {
                $x = trim((string) ($row['x'] ?? $row['text'] ?? ''));
                if ($x !== '') {
                    $out[] = ['t' => 'c', 'x' => $x];
                }
            }
        }

        return $out;
    }

    /**
     * Resolve onboarding / form submission (degree id and/or free text).
     *
     * @return array{
     *     education_degree_id: ?int,
     *     education_text: ?string,
     *     legacy_highest_education: ?string,
     *     mirror_highest_education_text: ?string,
     * }
     */
    public function resolveDegreeSelection(?int $selectedDegreeId, string $freeTextInput): array
    {
        $free = trim($freeTextInput);

        if ($selectedDegreeId !== null && $selectedDegreeId > 0) {
            $deg = EducationDegree::query()->find($selectedDegreeId);
            if ($deg) {
                $label = trim((string) ($deg->title ?: $deg->code));

                return [
                    'education_degree_id' => $deg->id,
                    'education_text' => null,
                    'legacy_highest_education' => $label !== '' ? $label : null,
                    'mirror_highest_education_text' => null,
                ];
            }
        }

        if ($free === '') {
            return [
                'education_degree_id' => null,
                'education_text' => null,
                'legacy_highest_education' => null,
                'mirror_highest_education_text' => null,
            ];
        }

        $match = $this->findDegreeMatch($free);
        if ($match) {
            $label = trim((string) ($match->title ?: $match->code));

            return [
                'education_degree_id' => $match->id,
                'education_text' => null,
                'legacy_highest_education' => $label !== '' ? $label : null,
                'mirror_highest_education_text' => null,
            ];
        }

        $text = mb_substr($free, 0, 512);
        $legacy = mb_substr($free, 0, 255);

        return [
            'education_degree_id' => null,
            'education_text' => $text,
            'legacy_highest_education' => $legacy,
            'mirror_highest_education_text' => $text,
        ];
    }

    /**
     * Display line for profile cards / UI (prefers FK, then free text, then legacy columns).
     */
    public function displayHighestEducation(MatrimonyProfile $profile): string
    {
        return trim((string) ($profile->highest_education ?? ''));
    }

    /**
     * Same display rules as {@see displayHighestEducation()} for intake preview / stdClass core objects (no Eloquent relations).
     */
    public function formatEducationDisplayLineFromObject(object $profile): string
    {
        return trim((string) ($profile->highest_education ?? ''));
    }

    /**
     * Distinct non-empty manual entries (optional admin review).
     *
     * @return Collection<int, object{text: string, cnt: int}>
     */
    public function distinctManualEducationTexts(): Collection
    {
        if (! Schema::hasColumn('matrimony_profiles', 'highest_education')) {
            return collect();
        }

        return DB::table('matrimony_profiles')
            ->selectRaw('highest_education as text, COUNT(*) as cnt')
            ->whereNotNull('highest_education')
            ->where('highest_education', '!=', '')
            ->groupBy('highest_education')
            ->orderByDesc('cnt')
            ->get();
    }

    private function degreeDisplayLabel(EducationDegree $deg): string
    {
        if (app()->getLocale() === 'mr' && filled($deg->title_mr)) {
            return trim((string) $deg->title_mr);
        }

        return trim((string) ($deg->title ?: $deg->code));
    }
}
