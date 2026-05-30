<?php

namespace App\Services\Intake;

use App\Models\MatrimonyProfile;
use App\Services\ControlledOptions\ControlledMasterDbAliasResolver;
use App\Services\MasterData\ReligionCasteSubCasteResolver;
use App\Support\BilingualMasterLabel;
use App\Support\HeightDisplay;
use App\Support\MasterData\MasterDataAliasNormalizer;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical equality for preview merge: compare religion/caste/etc. by resolved master ID,
 * not raw (int) cast on Marathi labels (fixes false "हिंदू" vs id 1 suggestions).
 */
class IntakePreviewMasterFieldEquivalence
{
    /** Religion/caste/subcaste: compare visible text variants (MR/EN/aliases), not raw id ints. */
    private const COMMUNITY_TEXT_ID_KEYS = [
        'religion_id',
        'caste_id',
        'sub_caste_id',
    ];

    /** @var array<string, string> core *_id key => controlled logical field */
    private const ID_TO_LOGICAL = [
        'religion_id' => 'religion',
        'caste_id' => 'caste',
        'sub_caste_id' => 'sub_caste',
        'gender_id' => 'gender',
        'marital_status_id' => 'marital_status',
        'mother_tongue_id' => 'mother_tongue',
        'complexion_id' => 'complexion',
        'blood_group_id' => 'blood_group',
        'physical_build_id' => 'physical_build',
        'diet_id' => 'diet',
        'smoking_status_id' => 'smoking_status',
        'drinking_status_id' => 'drinking_status',
        'family_type_id' => 'family_type',
    ];

    public function __construct(
        private ControlledMasterDbAliasResolver $dbAliases,
        private ReligionCasteSubCasteResolver $religionCasteResolver,
    ) {}

    public function isMasterIdField(string $coreKey): bool
    {
        return isset(self::ID_TO_LOGICAL[$coreKey]);
    }

    /**
     * @param  array<string, mixed>  $profileContext  Normalized profile core
     * @param  array<string, mixed>  $intakeContext  Normalized intake core
     */
    public function valuesEqual(
        string $coreKey,
        mixed $profileValue,
        mixed $intakeValue,
        array $profileContext,
        array $intakeContext,
        ?MatrimonyProfile $profile = null,
    ): bool {
        if (in_array($coreKey, self::COMMUNITY_TEXT_ID_KEYS, true)) {
            return $this->communityTextsEqual(
                $coreKey,
                $profileValue,
                $intakeValue,
                $profileContext,
                $intakeContext,
                $profile
            );
        }

        if (! $this->isMasterIdField($coreKey)) {
            return $this->scalarEquals($coreKey, $profileValue, $intakeValue);
        }

        $profileId = $this->canonicalMasterId($coreKey, $profileValue, $profileContext, $profile);
        $intakeId = $this->canonicalMasterId($coreKey, $intakeValue, $intakeContext, $profile);

        if ($profileId !== null && $intakeId !== null && $profileId === $intakeId) {
            return true;
        }

        $profileLabel = $this->displayLabelForValue($coreKey, $profileValue, $profileContext, $profileId);
        $intakeLabel = $this->displayLabelForValue($coreKey, $intakeValue, $intakeContext, $intakeId);
        if ($profileLabel !== '' && $intakeLabel !== '' && $this->labelsEquivalent($profileLabel, $intakeLabel)) {
            return true;
        }

        return $this->scalarEquals($coreKey, $profileValue, $intakeValue);
    }

    /**
     * @param  array<string, mixed>  $profileContext
     * @param  array<string, mixed>  $intakeContext
     */
    private function communityTextsEqual(
        string $coreKey,
        mixed $profileValue,
        mixed $intakeValue,
        array $profileContext,
        array $intakeContext,
        ?MatrimonyProfile $profile,
    ): bool {
        $profileTexts = $this->gatherComparableTexts($coreKey, $profileValue, $profileContext, $profile);
        $intakeTexts = $this->gatherComparableTexts($coreKey, $intakeValue, $intakeContext, $profile);

        if ($profileTexts === [] || $intakeTexts === []) {
            return false;
        }

        foreach ($profileTexts as $profileText) {
            foreach ($intakeTexts as $intakeText) {
                if ($this->labelsEquivalent($profileText, $intakeText)) {
                    return true;
                }
            }
        }

        $profileId = $this->canonicalMasterId($coreKey, $profileValue, $profileContext, $profile);
        $intakeId = $this->canonicalMasterId($coreKey, $intakeValue, $intakeContext, $profile);
        if ($profileId !== null && $intakeId !== null && $profileId === $intakeId) {
            return true;
        }

        foreach ($profileTexts as $profileText) {
            $resolvedFromProfile = $this->canonicalMasterId($coreKey, $profileText, $profileContext, $profile);
            foreach ($intakeTexts as $intakeText) {
                $resolvedFromIntake = $this->canonicalMasterId($coreKey, $intakeText, $intakeContext, $profile);
                if ($resolvedFromProfile !== null && $resolvedFromIntake !== null && $resolvedFromProfile === $resolvedFromIntake) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function gatherComparableTexts(
        string $coreKey,
        mixed $value,
        array $context,
        ?MatrimonyProfile $profile,
    ): array {
        $textKey = self::ID_TO_LOGICAL[$coreKey] ?? null;
        $texts = [];

        $add = static function (mixed $t) use (&$texts): void {
            if (! is_scalar($t)) {
                return;
            }
            $s = trim((string) $t);
            if ($s !== '' && $s !== '—' && $s !== '-') {
                $texts[] = $s;
            }
        };

        if ($textKey !== null) {
            $add($context[$textKey] ?? null);
        }
        if (is_string($value) && ! is_numeric($value)) {
            $add($value);
        }

        $masterId = null;
        if (is_numeric($value) && (int) $value > 0) {
            $masterId = (int) $value;
        } elseif ($textKey !== null && is_numeric($context[$coreKey] ?? null) && (int) $context[$coreKey] > 0) {
            $masterId = (int) $context[$coreKey];
        }

        if ($profile !== null) {
            if ($coreKey === 'religion_id' && $profile->religion) {
                $add($profile->religion->label_mr);
                $add($profile->religion->label_en);
                $add($profile->religion->label);
                $masterId = $masterId ?? (int) ($profile->religion_id ?? 0);
            }
            if ($coreKey === 'caste_id' && $profile->caste) {
                $add($profile->caste->label_mr);
                $add($profile->caste->label_en);
                $add($profile->caste->label);
                $masterId = $masterId ?? (int) ($profile->caste_id ?? 0);
            }
            if ($coreKey === 'sub_caste_id' && $profile->subCaste) {
                $add($profile->subCaste->label_mr);
                $add($profile->subCaste->label_en);
                $add($profile->subCaste->label);
                $masterId = $masterId ?? (int) ($profile->sub_caste_id ?? 0);
            }
        }

        if ($masterId !== null && $masterId > 0) {
            foreach ($this->labelsFromMasterId($coreKey, $masterId) as $lbl) {
                $add($lbl);
            }
            foreach ($this->aliasesForMasterId($coreKey, $masterId) as $alias) {
                $add($alias);
            }
        }

        return array_values(array_unique($texts));
    }

    /**
     * @return list<string>
     */
    private function labelsFromMasterId(string $coreKey, int $id): array
    {
        $single = $this->labelFromMasterId($coreKey, $id);

        return $single !== null && $single !== '' ? [$single] : [];
    }

    /**
     * @return list<string>
     */
    private function aliasesForMasterId(string $coreKey, int $masterId): array
    {
        $out = [];
        if ($coreKey === 'religion_id') {
            $rows = \App\Models\ReligionAlias::query()->where('religion_id', $masterId)->pluck('alias');
        } elseif ($coreKey === 'caste_id') {
            $rows = \App\Models\CasteAlias::query()->where('caste_id', $masterId)->pluck('alias');
        } elseif ($coreKey === 'sub_caste_id') {
            $rows = \App\Models\SubCasteAlias::query()->where('sub_caste_id', $masterId)->pluck('alias');
        } else {
            return [];
        }

        foreach ($rows as $alias) {
            $s = trim((string) $alias);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function canonicalMasterId(
        string $coreKey,
        mixed $value,
        array $context,
        ?MatrimonyProfile $profile = null,
    ): ?int {
        if ($value === null || $value === '') {
            $value = $this->textFallbackForIdKey($coreKey, $context);
        }
        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $logical = self::ID_TO_LOGICAL[$coreKey] ?? null;
        if ($logical === null) {
            return null;
        }

        if (in_array($coreKey, ['religion_id', 'caste_id', 'sub_caste_id'], true)) {
            return $this->resolveReligionCasteSubCasteId($coreKey, $raw, $context, $profile);
        }

        return $this->resolveViaAliasTable($logical, $raw, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveReligionCasteSubCasteId(
        string $coreKey,
        string $raw,
        array $context,
        ?MatrimonyProfile $profile,
    ): ?int {
        $resolveCtx = [
            'religion_id' => $context['religion_id'] ?? $profile?->religion_id,
            'caste_id' => $context['caste_id'] ?? $profile?->caste_id,
        ];

        $logical = self::ID_TO_LOGICAL[$coreKey];
        $id = $this->dbAliases->resolveReligionCasteSubCasteId($logical, $raw, $resolveCtx);
        if ($id !== null && $id > 0) {
            return $id;
        }

        $existingRel = is_numeric($context['religion_id'] ?? null) ? (int) $context['religion_id'] : ($profile?->religion_id);
        $existingCas = is_numeric($context['caste_id'] ?? null) ? (int) $context['caste_id'] : ($profile?->caste_id);
        $existingSub = is_numeric($context['sub_caste_id'] ?? null) ? (int) $context['sub_caste_id'] : ($profile?->sub_caste_id);

        $rRel = $coreKey === 'religion_id' ? $raw : null;
        $rCas = $coreKey === 'caste_id' ? $raw : null;
        $rSub = $coreKey === 'sub_caste_id' ? $raw : null;

        if ($coreKey === 'caste_id' && $rRel === null) {
            $rRel = trim((string) ($context['religion'] ?? ''));
        }
        if ($coreKey === 'sub_caste_id' && $rCas === null) {
            $rCas = trim((string) ($context['caste'] ?? ''));
        }

        $resolved = $this->religionCasteResolver->resolve(
            $rRel !== '' ? $rRel : null,
            $rCas !== '' ? $rCas : null,
            $rSub !== '' ? $rSub : null,
            $existingRel ? (int) $existingRel : null,
            $existingCas ? (int) $existingCas : null,
            $existingSub ? (int) $existingSub : null,
        );

        $key = match ($coreKey) {
            'religion_id' => 'religion_id',
            'caste_id' => 'caste_id',
            'sub_caste_id' => 'sub_caste_id',
            default => null,
        };
        if ($key === null) {
            return null;
        }
        $rid = $resolved[$key] ?? null;

        return is_numeric($rid) && (int) $rid > 0 ? (int) $rid : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveViaAliasTable(string $logical, string $raw, array $context): ?int
    {
        if (in_array($logical, ['religion', 'caste', 'sub_caste'], true)) {
            return null;
        }

        $id = $this->dbAliases->resolveReligionCasteSubCasteId($logical, $raw, $context);
        if ($id !== null && $id > 0) {
            return $id;
        }

        return $this->resolveMasterByLabels($logical, $raw);
    }

    private function resolveMasterByLabels(string $logical, string $raw): ?int
    {
        $lower = mb_strtolower(trim($raw));

        if ($logical === 'gender') {
            $row = \App\Models\MasterGender::query()
                ->where('is_active', true)
                ->where(function ($q) use ($lower, $raw) {
                    $q->whereRaw('LOWER(`key`) = ?', [$lower])
                        ->orWhereRaw('LOWER(label) = ?', [$lower]);
                })
                ->first();

            return $row ? (int) $row->id : null;
        }

        if ($logical === 'marital_status') {
            $norm = str_replace(' ', '_', $lower);
            if ($norm === 'unmarried') {
                $norm = 'never_married';
            }
            $row = \App\Models\MasterMaritalStatus::query()
                ->where('is_active', true)
                ->where(function ($q) use ($lower, $norm, $raw) {
                    $q->whereRaw('LOWER(`key`) = ?', [$norm])
                        ->orWhereRaw('LOWER(`key`) = ?', [$lower])
                        ->orWhereRaw('LOWER(label) = ?', [$lower])
                        ->orWhereRaw('LOWER(label_mr) = ?', [mb_strtolower($raw)]);
                })
                ->first();

            return $row ? (int) $row->id : null;
        }

        if ($logical === 'mother_tongue') {
            $row = \App\Models\MasterMotherTongue::query()
                ->where('is_active', true)
                ->where(function ($q) use ($lower, $raw) {
                    $q->whereRaw('LOWER(`key`) = ?', [$lower])
                        ->orWhereRaw('LOWER(label) = ?', [$lower])
                        ->orWhereRaw('LOWER(label_mr) = ?', [mb_strtolower($raw)]);
                })
                ->first();

            return $row ? (int) $row->id : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function textFallbackForIdKey(string $coreKey, array $context): mixed
    {
        foreach (self::ID_TO_LOGICAL as $idKey => $textKey) {
            if ($idKey === $coreKey && isset($context[$textKey]) && ! $this->isEmptyScalar($context[$textKey])) {
                return $context[$textKey];
            }
        }

        return null;
    }

    private function displayLabelForValue(
        string $coreKey,
        mixed $value,
        array $context,
        ?int $resolvedId,
    ): string {
        if ($resolvedId !== null && $resolvedId > 0) {
            return $this->labelFromMasterId($coreKey, $resolvedId) ?? '';
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return trim((string) ($this->textFallbackForIdKey($coreKey, $context) ?? ''));
    }

    private function labelFromMasterId(string $coreKey, int $id): ?string
    {
        if ($coreKey === 'religion_id') {
            $r = \App\Models\Religion::find($id);

            return $r ? BilingualMasterLabel::preferred($r->label_mr, $r->label_en, $r->label) : null;
        }
        if ($coreKey === 'caste_id') {
            $c = \App\Models\Caste::find($id);

            return $c ? BilingualMasterLabel::preferred($c->label_mr, $c->label_en, $c->label) : null;
        }
        if ($coreKey === 'sub_caste_id') {
            $s = \App\Models\SubCaste::find($id);

            return $s ? BilingualMasterLabel::preferred($s->label_mr, $s->label_en, $s->label) : null;
        }
        if ($coreKey === 'blood_group_id') {
            $row = \App\Models\MasterBloodGroup::find($id);
            if (! $row) {
                return null;
            }
            $key = $row->key ?? null;
            if ($key) {
                $tKey = 'components.options.blood_group.'.$key;
                $t = __($tKey);
                if ($t !== $tKey) {
                    return $t;
                }
            }

            return (string) ($row->label ?? $key ?? '');
        }

        return null;
    }

    private function labelsEquivalent(string $a, string $b): bool
    {
        $na = $this->normalizeLabelForCompare($a);
        $nb = $this->normalizeLabelForCompare($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb) {
            return true;
        }

        foreach (MasterDataAliasNormalizer::normalizedLookupCandidates($a) as $cand) {
            if ($cand === $nb) {
                return true;
            }
        }
        foreach (MasterDataAliasNormalizer::normalizedLookupCandidates($b) as $cand) {
            if ($cand === $na) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLabelForCompare(string $s): string
    {
        return MasterDataAliasNormalizer::normalizeForStoredAlias($s);
    }

    private function scalarEquals(string $fieldKey, mixed $current, mixed $proposed): bool
    {
        if ($current instanceof \DateTimeInterface) {
            $current = $current->format('Y-m-d');
        }
        if ($proposed instanceof \DateTimeInterface) {
            $proposed = $proposed->format('Y-m-d');
        }
        if (str_ends_with($fieldKey, '_id') && ! $this->isMasterIdField($fieldKey)) {
            return (int) ($current ?? 0) === (int) ($proposed ?? 0);
        }
        if ($fieldKey === 'date_of_birth') {
            try {
                $c = $current ? \Carbon\Carbon::parse((string) $current)->format('Y-m-d') : '';
                $p = $proposed ? \Carbon\Carbon::parse((string) $proposed)->format('Y-m-d') : '';

                return $c === $p;
            } catch (\Throwable) {
                return trim((string) $current) === trim((string) $proposed);
            }
        }
        if ($fieldKey === 'height_cm') {
            return $this->heightCmEquals($current, $proposed);
        }

        return trim(mb_strtolower((string) ($current ?? ''))) === trim(mb_strtolower((string) ($proposed ?? '')));
    }

    private function heightCmEquals(mixed $a, mixed $b): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            $fa = HeightDisplay::formatFeetInches(is_numeric($a) ? (int) round((float) $a) : 0);
            $fb = HeightDisplay::formatFeetInches(is_numeric($b) ? (int) round((float) $b) : 0);
            if ($fa !== '' && $fb !== '' && $fa === $fb) {
                return true;
            }

            return trim((string) ($a ?? '')) === trim((string) ($b ?? ''));
        }

        return (int) round((float) $a) === (int) round((float) $b);
    }

    private function isEmptyScalar(mixed $v): bool
    {
        return $v === null || $v === '' || (is_string($v) && trim($v) === '');
    }
}
