<?php

namespace App\Services\MasterData;

use App\Models\Caste;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Models\ReligionAlias;
use App\Models\CasteAlias;
use App\Models\SubCasteAlias;
use Illuminate\Support\Facades\DB;

class MasterDataTranslationImportService
{
    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function importFromDecodedJson(array $entries): void
    {
        DB::transaction(function () use ($entries) {
            foreach ($entries as $row) {
                $this->importOne($row);
            }
        });
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
                $norm = $this->normalizeAlias($alias);
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

    private function normalizeAlias(string $s): string
    {
        $t = mb_strtolower(trim($s));
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return $t;
    }
}
