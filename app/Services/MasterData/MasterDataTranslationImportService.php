<?php

namespace App\Services\MasterData;

use App\Models\Caste;
use App\Models\CasteAlias;
use App\Models\Religion;
use App\Models\ReligionAlias;
use App\Models\SubCaste;
use App\Models\SubCasteAlias;
use App\Support\MasterData\MasterDataAliasNormalizer;
use App\Support\MasterData\ReligionBilingualCatalog;
use Illuminate\Support\Facades\DB;

class MasterDataTranslationImportService
{
    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function importFromDecodedJson(array $entries): void
    {
        $entries = $this->mergeReligionRowsFromBilingualCatalog($entries);

        DB::transaction(function () use ($entries) {
            foreach ($entries as $row) {
                $this->importOne($row);
            }
        });
    }

    /**
     * Religion labels + aliases come only from {@see ReligionBilingualCatalog};
     * JSON entries with entity_type religion are ignored to avoid drift.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function mergeReligionRowsFromBilingualCatalog(array $entries): array
    {
        $catalog = ReligionBilingualCatalog::load();
        $rest = array_values(array_filter(
            $entries,
            fn ($r) => ($r['entity_type'] ?? '') !== 'religion'
        ));

        if ($catalog === []) {
            return $entries;
        }

        $prefix = [];
        foreach ($catalog as $key => $meta) {
            $key = trim((string) $key);
            $labelEn = trim((string) ($meta['label_en'] ?? ''));
            if ($key === '' || $labelEn === '') {
                continue;
            }
            $mr = trim((string) ($meta['label_mr'] ?? ''));
            $labelMr = $mr !== '' ? $mr : null;
            $prefix[] = $this->religionImportRowFromCatalog($key, $labelEn, $labelMr);
        }

        return array_merge($prefix, $rest);
    }

    /**
     * @return array<string, mixed>
     */
    private function religionImportRowFromCatalog(string $key, string $labelEn, ?string $labelMr): array
    {
        $aliasesEn = array_values(array_unique(array_filter([
            $labelEn,
            mb_strtolower($labelEn),
        ])));
        $aliasesMr = array_values(array_unique(array_filter($labelMr !== null && $labelMr !== '' ? [$labelMr] : [])));

        return [
            'entity_type' => 'religion',
            'key' => $key,
            'scope' => [],
            'label_en' => $labelEn,
            'label_mr' => $labelMr,
            'aliases_en' => $aliasesEn,
            'aliases_mr' => $aliasesMr,
            'ocr_variants' => array_values(array_unique(array_filter(array_merge($aliasesEn, $aliasesMr)))),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importOne(array $row): void
    {
        $type = $row['entity_type'] ?? '';
        $key = $row['key'] ?? '';
        $scope = $row['scope'] ?? [];
        $labelEn = $row['label_en'] ?? '';
        $labelMr = $row['label_mr'] ?? null;

        if ($type === 'religion') {
            $rel = Religion::where('key', $key)->first();
            if (! $rel) {
                return;
            }
            $rel->update([
                'label_en' => $labelEn,
                'label_mr' => $labelMr,
                'label' => $labelEn,
            ]);
            $this->syncReligionAliases($rel->id, $row);

            return;
        }

        if ($type === 'caste') {
            $relKey = $scope['religion_key'] ?? null;
            if (! $relKey) {
                return;
            }
            $rel = Religion::where('key', $relKey)->first();
            if (! $rel) {
                return;
            }
            $caste = Caste::where('religion_id', $rel->id)->where('key', $key)->first();
            if (! $caste) {
                return;
            }
            $caste->update([
                'label_en' => $labelEn,
                'label_mr' => $labelMr,
                'label' => $labelEn,
            ]);
            $this->syncCasteAliases($caste->id, $row);

            return;
        }

        if ($type === 'sub_caste') {
            $relKey = $scope['religion_key'] ?? null;
            $casteKey = $scope['caste_key'] ?? null;
            if (! $relKey || ! $casteKey) {
                return;
            }
            $rel = Religion::where('key', $relKey)->first();
            if (! $rel) {
                return;
            }
            $caste = Caste::where('religion_id', $rel->id)->where('key', $casteKey)->first();
            if (! $caste) {
                return;
            }
            $sub = SubCaste::where('caste_id', $caste->id)->where('key', $key)->first();
            if (! $sub) {
                return;
            }
            $sub->update([
                'label_en' => $labelEn,
                'label_mr' => $labelMr,
                'label' => $labelEn,
            ]);
            $this->syncSubCasteAliases($sub->id, $row);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function syncReligionAliases(int $religionId, array $row): void
    {
        ReligionAlias::where('religion_id', $religionId)->delete();
        $this->insertAliases('religion', $religionId, $row);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function syncCasteAliases(int $casteId, array $row): void
    {
        CasteAlias::where('caste_id', $casteId)->delete();
        $this->insertAliases('caste', $casteId, $row);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function syncSubCasteAliases(int $subCasteId, array $row): void
    {
        SubCasteAlias::where('sub_caste_id', $subCasteId)->delete();
        $this->insertAliases('sub_caste', $subCasteId, $row);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertAliases(string $kind, int $parentId, array $row): void
    {
        $buckets = [
            'en' => $row['aliases_en'] ?? [],
            'mr' => $row['aliases_mr'] ?? [],
            'ocr' => $row['ocr_variants'] ?? [],
        ];
        foreach ($buckets as $type => $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    continue;
                }
                $norm = MasterDataAliasNormalizer::normalizeForStoredAlias($alias);
                if ($norm === '') {
                    continue;
                }
                $data = [
                    'alias' => $alias,
                    'alias_type' => $type,
                    'normalized_alias' => $norm,
                ];
                if ($kind === 'religion') {
                    $data['religion_id'] = $parentId;
                    ReligionAlias::create($data);
                } elseif ($kind === 'caste') {
                    $data['caste_id'] = $parentId;
                    CasteAlias::create($data);
                } else {
                    $data['sub_caste_id'] = $parentId;
                    SubCasteAlias::create($data);
                }
            }
        }
    }
}
