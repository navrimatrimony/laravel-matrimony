<?php

namespace App\Services;

use App\Models\OccupationCategory;
use App\Models\OccupationCustom;
use App\Models\OccupationMaster;
use App\Models\Profession;
use App\Support\MasterData\MasterDataAliasNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OccupationService
{
    private const MIN_SEARCH_LEN = 2;

    /**
     * Search occupation_master rows (LIKE + simple ranking). Max 10 results.
     *
     * @return array{occupations: \Illuminate\Support\Collection<int, OccupationMaster>, suggestion: ?string}
     */
    /**
     * Intake / OCR: resolve free-text title to a single master row when a DB alias row exists.
     */
    public function findOccupationMasterForIntake(string $raw): ?OccupationMaster
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (Schema::hasTable('occupation_master_aliases')) {
            foreach (MasterDataAliasNormalizer::normalizedLookupCandidates($raw) as $norm) {
                if ($norm === '') {
                    continue;
                }
                $mid = DB::table('occupation_master_aliases')
                    ->where('is_active', true)
                    ->where('normalized_alias', $norm)
                    ->value('occupation_master_id');
                if ($mid !== null) {
                    $row = OccupationMaster::query()->with('category')->find((int) $mid);
                    if ($row !== null) {
                        return $row;
                    }
                }
            }
        }

        return null;
    }

    public function search(string $query, int $limit = 10): array
    {
        $q = trim($query);
        if (mb_strlen($q) < self::MIN_SEARCH_LEN) {
            return ['occupations' => collect(), 'suggestion' => null];
        }

        $safeLowerLike = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
        $qNorm = $this->normalizeToken($q);
        $qLower = mb_strtolower($q);

        $take = max(1, min($limit, 10));
        /** Pool rows then rank in PHP so every surface (onboarding, wizard, API) shares one relevance order. */
        $poolLimit = min(120, max($take * 12, 60));

        $rows = OccupationMaster::query()
            ->with('category')
            ->where(function ($w) use ($safeLowerLike, $qNorm) {
                $w->whereRaw('LOWER(name) LIKE ?', [$safeLowerLike])
                    ->orWhereRaw('LOWER(COALESCE(normalized_name, "")) LIKE ?', [$safeLowerLike]);
                if (Schema::hasColumn('occupation_master', 'name_mr')) {
                    $w->orWhereRaw('LOWER(COALESCE(name_mr, "")) LIKE ?', [$safeLowerLike]);
                }
                if ($qNorm !== '') {
                    $w->orWhereRaw(
                        "REPLACE(REPLACE(LOWER(name), '.', ''), ' ', '') LIKE ?",
                        ['%'.addcslashes($qNorm, '%_\\').'%']
                    );
                }
            })
            ->limit($poolLimit)
            ->get()
            ->sort(function (OccupationMaster $a, OccupationMaster $b) use ($qLower, $qNorm): int {
                $ta = $this->occupationSearchRankTuple($a, $qLower, $qNorm);
                $tb = $this->occupationSearchRankTuple($b, $qLower, $qNorm);

                return $ta <=> $tb;
            })
            ->values()
            ->take($take);

        return [
            'occupations' => $rows,
            'suggestion' => null,
        ];
    }

    /**
     * Persist user-typed occupation (pending review). Dedupes by normalized_name + user pending.
     *
     * @return array{id: int, name: string}
     */
    public function createCustom(string $rawName, int $userId): array
    {
        $name = trim($rawName);
        if (mb_strlen($name) < self::MIN_SEARCH_LEN) {
            throw ValidationException::withMessages([
                'name' => [__('validation.min.string', ['attribute' => 'name', 'min' => self::MIN_SEARCH_LEN])],
            ]);
        }

        $normalized = $this->normalizeStoredName($name);

        $existing = OccupationCustom::query()
            ->where('user_id', $userId)
            ->where('normalized_name', $normalized)
            ->first();

        if ($existing) {
            return ['id' => $existing->id, 'name' => $existing->raw_name];
        }

        $row = OccupationCustom::create([
            'raw_name' => Str::limit($name, 160, ''),
            'normalized_name' => Str::limit($normalized, 160, ''),
            'user_id' => $userId,
            'status' => 'pending',
        ]);

        return ['id' => $row->id, 'name' => $row->raw_name];
    }

    /**
     * Category label + full list for inline picker (UI).
     *
     * @return array{category: array{id: int, name: string, icon: string}|null, categories: list<array{id: int, name: string, icon: string}>}
     */
    public function getCategoryPayload(OccupationMaster $occupation): array
    {
        $occupation->loadMissing('category');

        $catCols = ['id', 'name'];
        if (Schema::hasColumn('occupation_categories', 'name_mr')) {
            $catCols[] = 'name_mr';
        }

        $categories = OccupationCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get($catCols);

        $cat = $occupation->category;

        return [
            'category' => $cat ? [
                'id' => $cat->id,
                'name' => $cat->name,
                'name_mr' => Schema::hasColumn('occupation_categories', 'name_mr') ? ($cat->name_mr ?? null) : null,
                'icon' => $this->categoryDisplayIcon($cat->name),
            ] : null,
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'name_mr' => Schema::hasColumn('occupation_categories', 'name_mr') ? ($c->name_mr ?? null) : null,
                'icon' => $this->categoryDisplayIcon($c->name),
            ])->values()->all(),
        ];
    }

    /**
     * Visual cue for workplace type (shared by API + UI). Keyword-based so seeded category names stay flexible.
     */
    /**
     * Human-readable label for a master or custom selection (for denormalized text columns).
     */
    public function resolvedOccupationText(?int $occupationMasterId, ?int $occupationCustomId, ?int $occupationCustomUserId = null): ?string
    {
        $mid = ($occupationMasterId !== null && $occupationMasterId > 0) ? $occupationMasterId : null;
        $cid = ($occupationCustomId !== null && $occupationCustomId > 0) ? $occupationCustomId : null;
        if ($mid) {
            $name = OccupationMaster::whereKey($mid)->value('name');

            return $name !== null && trim((string) $name) !== '' ? trim((string) $name) : null;
        }
        if ($cid) {
            $q = OccupationCustom::query()->whereKey($cid);
            $uid = $occupationCustomUserId ?? auth()->id();
            if ($uid) {
                $q->where('user_id', $uid);
            }
            $raw = $q->value('raw_name');

            return $raw !== null && trim((string) $raw) !== '' ? trim((string) $raw) : null;
        }

        return null;
    }

    /**
     * Merge legacy father_occupation / mother_occupation strings from posted occupation_* IDs.
     */
    public function mergeParentOccupationTextIntoRequest(\Illuminate\Http\Request $request): void
    {
        if (! Schema::hasColumn('matrimony_profiles', 'father_occupation_master_id')) {
            return;
        }
        $uid = auth()->id();
        $fatherText = $this->resolvedOccupationText(
            $request->filled('father_occupation_master_id') ? (int) $request->input('father_occupation_master_id') : null,
            $request->filled('father_occupation_custom_id') ? (int) $request->input('father_occupation_custom_id') : null,
            $uid
        );
        if ($fatherText !== null) {
            $request->merge(['father_occupation' => $fatherText]);
        }
        $motherText = $this->resolvedOccupationText(
            $request->filled('mother_occupation_master_id') ? (int) $request->input('mother_occupation_master_id') : null,
            $request->filled('mother_occupation_custom_id') ? (int) $request->input('mother_occupation_custom_id') : null,
            $uid
        );
        if ($motherText !== null) {
            $request->merge(['mother_occupation' => $motherText]);
        }
    }

    public function categoryDisplayIcon(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '📋';
        }

        $n = mb_strtolower(trim($name));

        return match (true) {
            str_contains($n, 'private') || str_contains($n, 'corporate') || str_contains($n, 'company') || str_contains($n, 'mnc') => '🏢',
            str_contains($n, 'gov') || str_contains($n, 'government') || str_contains($n, 'public sector') || str_contains($n, 'सरकार') => '🏛️',
            str_contains($n, 'self-employed') || str_contains($n, 'self employed') || str_contains($n, 'freelance') || str_contains($n, 'entrepreneur') => '💼',
            str_contains($n, 'business') && ! str_contains($n, 'gov') => '🏪',
            str_contains($n, 'ngo') || str_contains($n, 'non-profit') || str_contains($n, 'nonprofit') || str_contains($n, 'social') => '🤝',
            str_contains($n, 'home') || str_contains($n, 'housemaker') || str_contains($n, 'homemaker') => '🏠',
            str_contains($n, 'abroad') || str_contains($n, 'international') || str_contains($n, 'overseas') || str_contains($n, 'foreign') => '✈️',
            str_contains($n, 'defence') || str_contains($n, 'defense') || str_contains($n, 'army') || str_contains($n, 'नौदल') => '🎖️',
            str_contains($n, 'education') || str_contains($n, 'school') || str_contains($n, 'university') || str_contains($n, 'teaching') => '🎓',
            default => '📋',
        };
    }

    /**
     * Maps posted occupation_* fields into legacy career columns for snapshots (additive columns).
     */
    public function mergeOccupationIntoRequest(Request $request): bool
    {
        if (! Schema::hasColumn('matrimony_profiles', 'occupation_master_id')) {
            return false;
        }

        if (! $request->hasAny(['occupation_master_id', 'occupation_custom_id'])) {
            return false;
        }

        $midRaw = $request->input('occupation_master_id');
        $cidRaw = $request->input('occupation_custom_id');

        $mid = ($midRaw !== null && $midRaw !== '') ? (int) $midRaw : null;
        $cid = ($cidRaw !== null && $cidRaw !== '') ? (int) $cidRaw : null;

        if (($mid === null || $mid <= 0) && ($cid === null || $cid <= 0)) {
            $request->merge([
                'occupation_master_id' => null,
                'occupation_custom_id' => null,
                'working_with_type_id' => null,
                'profession_id' => null,
            ]);

            return true;
        }

        if ($mid !== null && $mid > 0) {
            $occ = OccupationMaster::with('category')->find($mid);
            if (! $occ) {
                return false;
            }

            $legacy = $occ->category?->legacy_working_with_type_id;
            $workingWithTypeId = $legacy ? (int) $legacy : null;

            $professionQuery = Profession::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($occ->name))])
                ->where('is_active', true);

            if ($workingWithTypeId) {
                $professionQuery->where('working_with_type_id', $workingWithTypeId);
            }

            $professionId = $professionQuery->value('id');

            if (! $professionId) {
                $professionId = Profession::query()
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($occ->name))])
                    ->where('is_active', true)
                    ->value('id');
            }

            $request->merge([
                'occupation_master_id' => $occ->id,
                'occupation_custom_id' => null,
                'working_with_type_id' => $workingWithTypeId,
                'profession_id' => $professionId ? (int) $professionId : null,
            ]);

            return true;
        }

        if ($cid !== null && $cid > 0) {
            $uid = auth()->id();
            if (! $uid) {
                return false;
            }

            $custom = OccupationCustom::query()->where('user_id', $uid)->find($cid);
            if ($custom) {
                $request->merge([
                    'occupation_master_id' => null,
                    'occupation_custom_id' => $custom->id,
                    'working_with_type_id' => null,
                    'profession_id' => null,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Lower tuple = better match: prefix first, then earliest substring index, then shorter label.
     *
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private function occupationSearchRankTuple(OccupationMaster $o, string $qLower, string $qNorm): array
    {
        $name = mb_strtolower(trim($o->name));
        $ql = $qLower;

        if ($ql !== '' && str_starts_with($name, $ql)) {
            return [0, 0, mb_strlen($name), $name];
        }

        if ($ql !== '') {
            $pos = mb_strpos($name, $ql);
            if ($pos !== false) {
                return [1, $pos, mb_strlen($name), $name];
            }
        }

        if ($qNorm !== '') {
            $compact = preg_replace('/[^a-z0-9]/u', '', $name);
            $pn = strpos($compact, $qNorm);
            if ($pn !== false) {
                return [2, $pn, mb_strlen($name), $name];
            }
        }

        return [9, 99, mb_strlen($name), $name];
    }

    private function normalizeToken(string $s): string
    {
        return preg_replace('/[^a-z0-9]/i', '', mb_strtolower(trim($s))) ?? '';
    }

    private function normalizeStoredName(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }
}
