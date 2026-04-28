<?php

namespace App\Services\ControlledOptions;

use App\Models\CasteAlias;
use App\Models\ReligionAlias;
use App\Models\SubCasteAlias;
use App\Support\MasterData\MasterDataAliasNormalizer;
use Illuminate\Support\Facades\Schema;

/**
 * DB-backed alias rows for intake-controlled resolution (additive; no fuzzy logic).
 */
final class ControlledMasterDbAliasResolver
{
    /**
     * @param  array{religion_id?: int|null, caste_id?: int|null}  $context
     */
    public function resolveReligionCasteSubCasteId(string $logicalField, string $raw, array $context = []): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $candidates = MasterDataAliasNormalizer::normalizedLookupCandidates($raw);
        if ($candidates === []) {
            return null;
        }

        return match ($logicalField) {
            'religion' => $this->resolveReligionId($candidates),
            'caste' => $this->resolveCasteId($candidates, $context['religion_id'] ?? null),
            'sub_caste' => $this->resolveSubCasteId($candidates, $context['caste_id'] ?? null),
            default => null,
        };
    }

    /**
     * @param  list<string>  $candidates
     */
    private function resolveReligionId(array $candidates): ?int
    {
        if (! Schema::hasTable('religion_aliases')) {
            return null;
        }

        $id = ReligionAlias::query()
            ->whereIn('normalized_alias', $candidates)
            ->orderBy('id')
            ->value('religion_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function resolveCasteId(array $candidates, ?int $religionId): ?int
    {
        if (! Schema::hasTable('caste_aliases') || ! Schema::hasTable('castes')) {
            return null;
        }

        $q = CasteAlias::query()->whereIn('normalized_alias', $candidates);
        if ($religionId !== null && $religionId > 0) {
            $q->whereHas('caste', fn ($c) => $c->where('religion_id', $religionId));
        }
        $id = $q->orderBy('id')->value('caste_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function resolveSubCasteId(array $candidates, ?int $casteId): ?int
    {
        if (! Schema::hasTable('sub_caste_aliases')) {
            return null;
        }

        $q = SubCasteAlias::query()->whereIn('normalized_alias', $candidates);
        if ($casteId !== null && $casteId > 0) {
            $q->whereHas('subCaste', fn ($s) => $s->where('caste_id', $casteId));
        }

        $id = $q->orderBy('id')->value('sub_caste_id');

        return $id !== null ? (int) $id : null;
    }
}
