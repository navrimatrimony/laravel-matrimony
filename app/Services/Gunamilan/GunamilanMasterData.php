<?php

namespace App\Services\Gunamilan;

use App\Support\LocalizedText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one in-memory copy of every static master table Gunamilan needs.
 *
 * Gunamilan is pure table lookup over tiny, effectively immutable data:
 * 12 rashis, 27 nakshatras, 14 yonis, 4 varnas, 5 vashyas, 3 gans, 3 nadis,
 * 9 rashi lords, 7 mangal dosh types. Reading those per pair with
 * `DB::table()` cost 24 queries per comparison, which made the engine unusable
 * inside the matching feed (200 candidates x 4 relaxation tiers = ~19k queries).
 *
 * This class loads all of it ONCE (container singleton + `Cache::rememberForever`)
 * and hands out plain arrays, so a pair comparison is pure array math and issues
 * zero queries after warm-up.
 *
 * ## Canonical yoni keys (FROZEN)
 *
 * `master_yonis` shipped the same 14 animals twice: the Sanskrit keys
 * (`ashwa`, `gaja`, …, ids 1-14) and an English set (`horse`, `elephant`, …,
 * ids 43-56). `master_nakshatra_attributes` — the autofill authority that
 * derives yoni from nakshatra — points at the **Sanskrit** rows, so the
 * Sanskrit key set is canonical. The English rows are deactivated and remapped
 * by `2026_07_26_100100_normalize_master_yoni_canonical_keys`.
 * {@see self::YONI_ALIASES} still resolves the retired spellings so a legacy id
 * that survives anywhere (an old export, a stale cache, production drift)
 * scores identically instead of silently failing the enemy-pair test.
 *
 * ## "other" is not a value
 *
 * `master_gans`, `master_nadis`, `master_yonis`, `master_rashis` (id 14) and
 * `master_nakshatras` (id 29) each carry an `other` row. It means "the person
 * did not know", which is an ABSENT input, not a factor value — comparing two
 * `other` nadis as equal would invent a Nadi dosha out of nothing, and two
 * `other` yonis would hand out a full 4 points. {@see self::isComputableKey()}
 * treats those rows as missing everywhere in the engine.
 */
final class GunamilanMasterData
{
    public const CACHE_KEY = 'gunamilan:master_data:v2';

    /**
     * Retired yoni spelling => canonical Sanskrit key.
     *
     * Covers the English duplicates and the male/female polarity variants that
     * `YoniPolaritySeeder` used to create. `goat` maps onto `mesha` (ram/sheep),
     * which is the same yoni.
     */
    public const YONI_ALIASES = [
        'horse' => 'ashwa',
        'elephant' => 'gaja',
        'sheep' => 'mesha',
        'goat' => 'mesha',
        'ram' => 'mesha',
        'serpent' => 'sarpa',
        'snake' => 'sarpa',
        'dog' => 'shwan',
        'cat' => 'marjar',
        'rat' => 'mushak',
        'mouse' => 'mushak',
        'cow' => 'gau',
        'buffalo' => 'mahish',
        'tiger' => 'vyaghra',
        'deer' => 'mrga',
        'mriga' => 'mrga',
        'monkey' => 'vanar',
        'lion' => 'singh',
        'mongoose' => 'nakul',
    ];

    /** The 14 canonical yoni keys, in `master_nakshatra_attributes` order. */
    public const CANONICAL_YONI_KEYS = [
        'ashwa', 'gaja', 'mesha', 'sarpa', 'shwan', 'marjar', 'mushak',
        'gau', 'mahish', 'vyaghra', 'mrga', 'vanar', 'nakul', 'singh',
    ];

    /**
     * Master rows whose key means "not filled in". Never a comparable value.
     */
    private const NON_COMPUTABLE_KEYS = ['other', 'unknown', 'none_of_these', 'not_applicable', 'na'];

    /** @var array<string, mixed>|null In-process memo; this class is a container singleton. */
    private ?array $data = null;

    /**
     * @return array{
     *     rashis: array<int, array<string, mixed>>,
     *     nakshatras: array<int, array<string, mixed>>,
     *     nakshatra_attributes: array<int, array<string, mixed>>,
     *     yonis: array<int, array<string, mixed>>,
     *     gans: array<int, array<string, mixed>>,
     *     nadis: array<int, array<string, mixed>>,
     *     varnas: array<int, array<string, mixed>>,
     *     vashyas: array<int, array<string, mixed>>,
     *     rashi_lords: array<int, array<string, mixed>>,
     *     mangal_dosh_types: array<int, array<string, mixed>>
     * }
     */
    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        return $this->data = Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->load());
    }

    /**
     * Drop both the in-process memo and the shared cache entry.
     * Call after seeding or editing any horoscope master table.
     */
    public function forget(): void
    {
        $this->data = null;
        Cache::forget(self::CACHE_KEY);
    }

    // ---------- lookups (all pure array reads) ----------

    /** @return array{id:int,key:string,label:string,label_mr:?string,position:?int,varna_id:?int,vashya_id:?int,rashi_lord_id:?int,is_active:bool}|null */
    public function rashi(?int $id): ?array
    {
        return $id === null ? null : ($this->all()['rashis'][$id] ?? null);
    }

    /** @return array{id:int,key:string,label:string,label_mr:?string,number:?int,is_active:bool}|null */
    public function nakshatra(?int $id): ?array
    {
        return $id === null ? null : ($this->all()['nakshatras'][$id] ?? null);
    }

    /** @return array{gan_id:?int,nadi_id:?int,yoni_id:?int}|null */
    public function nakshatraAttributes(?int $nakshatraId): ?array
    {
        return $nakshatraId === null ? null : ($this->all()['nakshatra_attributes'][$nakshatraId] ?? null);
    }

    public function yoni(?int $id): ?array
    {
        return $this->lookup('yonis', $id);
    }

    public function gan(?int $id): ?array
    {
        return $this->lookup('gans', $id);
    }

    public function nadi(?int $id): ?array
    {
        return $this->lookup('nadis', $id);
    }

    public function varna(?int $id): ?array
    {
        return $this->lookup('varnas', $id);
    }

    public function vashya(?int $id): ?array
    {
        return $this->lookup('vashyas', $id);
    }

    public function rashiLord(?int $id): ?array
    {
        return $this->lookup('rashi_lords', $id);
    }

    public function mangalDoshType(?int $id): ?array
    {
        return $this->lookup('mangal_dosh_types', $id);
    }

    /**
     * The canonical yoni key for a `master_yonis.id`, whichever spelling that
     * row happens to store. Returns null for the `other` sentinel and for ids
     * that no longer exist.
     */
    public function canonicalYoniKey(?int $yoniId): ?string
    {
        $row = $this->yoni($yoniId);
        if ($row === null) {
            return null;
        }

        return self::canonicalYoniKeyFor((string) $row['key']);
    }

    /**
     * The `master_yonis` row that OWNS a canonical key — i.e. the Sanskrit row,
     * not a retired English duplicate. Used so a legacy id still renders the
     * canonical label.
     */
    public function yoniRowForCanonicalKey(?string $canonicalKey): ?array
    {
        if ($canonicalKey === null) {
            return null;
        }

        $id = $this->all()['yoni_canonical_index'][$canonicalKey] ?? null;

        return $id === null ? null : $this->yoni((int) $id);
    }

    /** Normalise any yoni spelling (English duplicate, `_male`/`_female` variant) to its canonical key. */
    public static function canonicalYoniKeyFor(?string $key): ?string
    {
        $key = strtolower(trim((string) $key));
        if ($key === '' || ! self::isComputableKey($key)) {
            return null;
        }

        $key = preg_replace('/_(male|female)$/', '', $key) ?? $key;

        $key = self::YONI_ALIASES[$key] ?? $key;

        return in_array($key, self::CANONICAL_YONI_KEYS, true) ? $key : null;
    }

    /**
     * False for the `other` / `unknown` sentinel rows, which mean "not filled
     * in" and must never be compared as if they were a real value.
     */
    public static function isComputableKey(?string $key): bool
    {
        $key = strtolower(trim((string) $key));

        return $key !== '' && ! in_array($key, self::NON_COMPUTABLE_KEYS, true);
    }

    /** Locale-aware display label for any cached master row. */
    public static function label(?array $row): string
    {
        if ($row === null) {
            return '';
        }

        return LocalizedText::pick($row['label_mr'] ?? null, $row['label'] ?? null);
    }

    // ---------- loading ----------

    private function lookup(string $bucket, ?int $id): ?array
    {
        return $id === null ? null : ($this->all()[$bucket][$id] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $yonis = $this->loadKeyLabel('master_yonis');

        return [
            'rashis' => $this->loadRashis(),
            'nakshatras' => $this->loadNakshatras(),
            'nakshatra_attributes' => $this->loadNakshatraAttributes(),
            'yonis' => $yonis,
            'yoni_canonical_index' => $this->indexYonisByCanonicalKey($yonis),
            'gans' => $this->loadKeyLabel('master_gans'),
            'nadis' => $this->loadKeyLabel('master_nadis'),
            'varnas' => $this->loadKeyLabel('master_varnas'),
            'vashyas' => $this->loadKeyLabel('master_vashyas'),
            'rashi_lords' => $this->loadKeyLabel('master_rashi_lords'),
            'mangal_dosh_types' => $this->loadKeyLabel('master_mangal_dosh_types'),
        ];
    }

    /**
     * canonical key => the id of the row that owns it. A row whose own key IS
     * the canonical key always wins over an alias row.
     *
     * @param  array<int, array<string, mixed>>  $yonis
     * @return array<string, int>
     */
    private function indexYonisByCanonicalKey(array $yonis): array
    {
        $index = [];
        foreach ($yonis as $id => $row) {
            $canonical = self::canonicalYoniKeyFor((string) $row['key']);
            if ($canonical === null) {
                continue;
            }
            $isOwnKey = $row['key'] === $canonical;
            if ($isOwnKey || ! isset($index[$canonical])) {
                $index[$canonical] = (int) $id;
            }
        }

        return $index;
    }

    /** Rashi key => zodiac position 1-12. The `other` row has no position. */
    public const RASHI_POSITION = [
        'mesha' => 1, 'vrishabha' => 2, 'mithuna' => 3, 'karka' => 4, 'simha' => 5, 'kanya' => 6,
        'tula' => 7, 'vrishchika' => 8, 'dhanu' => 9, 'makara' => 10, 'kumbha' => 11, 'meena' => 12,
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRashis(): array
    {
        if (! Schema::hasTable('master_rashis')) {
            return [];
        }

        $hasAshtakoota = Schema::hasColumn('master_rashis', 'varna_id');
        $columns = array_merge(
            ['id', 'key', 'label', 'is_active'],
            Schema::hasColumn('master_rashis', 'label_mr') ? ['label_mr'] : [],
            $hasAshtakoota ? ['varna_id', 'vashya_id', 'rashi_lord_id'] : [],
        );

        $out = [];
        foreach (DB::table('master_rashis')->get($columns) as $row) {
            $key = strtolower(trim((string) $row->key));
            $out[(int) $row->id] = [
                'id' => (int) $row->id,
                'key' => $key,
                'label' => (string) ($row->label ?? ''),
                'label_mr' => $row->label_mr ?? null,
                'position' => self::RASHI_POSITION[$key] ?? null,
                'varna_id' => isset($row->varna_id) && $row->varna_id !== null ? (int) $row->varna_id : null,
                'vashya_id' => isset($row->vashya_id) && $row->vashya_id !== null ? (int) $row->vashya_id : null,
                'rashi_lord_id' => isset($row->rashi_lord_id) && $row->rashi_lord_id !== null ? (int) $row->rashi_lord_id : null,
                'is_active' => (bool) $row->is_active,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadNakshatras(): array
    {
        if (! Schema::hasTable('master_nakshatras')) {
            return [];
        }

        $hasNumber = Schema::hasColumn('master_nakshatras', 'nakshatra_number');
        $columns = array_merge(
            ['id', 'key', 'label', 'is_active'],
            Schema::hasColumn('master_nakshatras', 'label_mr') ? ['label_mr'] : [],
            $hasNumber ? ['nakshatra_number'] : [],
        );

        $out = [];
        foreach (DB::table('master_nakshatras')->get($columns) as $row) {
            $number = $hasNumber && $row->nakshatra_number !== null ? (int) $row->nakshatra_number : null;
            if ($number !== null && ($number < 1 || $number > 27)) {
                $number = null;
            }
            $out[(int) $row->id] = [
                'id' => (int) $row->id,
                'key' => strtolower(trim((string) $row->key)),
                'label' => (string) ($row->label ?? ''),
                'label_mr' => $row->label_mr ?? null,
                'number' => $number,
                'is_active' => (bool) $row->is_active,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadNakshatraAttributes(): array
    {
        if (! Schema::hasTable('master_nakshatra_attributes')) {
            return [];
        }

        $out = [];
        $rows = DB::table('master_nakshatra_attributes')
            ->where('is_active', true)
            ->get(['nakshatra_id', 'gan_id', 'nadi_id', 'yoni_id']);

        foreach ($rows as $row) {
            $out[(int) $row->nakshatra_id] = [
                'gan_id' => $row->gan_id !== null ? (int) $row->gan_id : null,
                'nadi_id' => $row->nadi_id !== null ? (int) $row->nadi_id : null,
                'yoni_id' => $row->yoni_id !== null ? (int) $row->yoni_id : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadKeyLabel(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = array_merge(
            ['id', 'key', 'label', 'is_active'],
            Schema::hasColumn($table, 'label_mr') ? ['label_mr'] : [],
        );

        $out = [];
        foreach (DB::table($table)->get($columns) as $row) {
            $out[(int) $row->id] = [
                'id' => (int) $row->id,
                'key' => strtolower(trim((string) $row->key)),
                'label' => (string) ($row->label ?? ''),
                'label_mr' => $row->label_mr ?? null,
                'is_active' => (bool) $row->is_active,
            ];
        }

        return $out;
    }
}
