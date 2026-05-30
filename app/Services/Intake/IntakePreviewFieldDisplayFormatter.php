<?php

namespace App\Services\Intake;

use App\Models\Caste;
use App\Models\MasterBloodGroup;
use App\Models\MasterComplexion;
use App\Models\MasterMaritalStatus;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SubCaste;
use App\Support\BilingualMasterLabel;
use App\Support\HeightDisplay;

/**
 * Human-readable labels for intake preview suggestions (never raw DB ids / bare cm decimals).
 */
class IntakePreviewFieldDisplayFormatter
{
    /**
     * @param  array<string, mixed>  $context  Normalized core context
     */
    public function format(string $coreKey, mixed $value, array $context = [], ?MatrimonyProfile $profile = null): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($coreKey === 'height_cm') {
            return $this->formatHeightCm($value);
        }

        if ($coreKey === 'blood_group_id') {
            return $this->formatBloodGroupId($value, $context, $profile);
        }

        if ($coreKey === 'complexion_id') {
            return $this->formatComplexionId($value);
        }

        if ($coreKey === 'religion_id') {
            return $this->formatReligionId($value, $context, $profile);
        }

        if ($coreKey === 'caste_id') {
            return $this->formatCasteId($value, $context, $profile);
        }

        if ($coreKey === 'sub_caste_id') {
            return $this->formatSubCasteId($value, $context, $profile);
        }

        if ($coreKey === 'marital_status_id') {
            return $this->formatMaritalStatusId($value);
        }

        if ($coreKey === 'gender_id') {
            $id = (int) $value;
            if ($id > 0) {
                $g = \App\Models\MasterGender::find($id);

                return $g ? (string) ($g->key === 'male' ? __('wizard.male') : __('wizard.female')) : (string) $value;
            }
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function formatHeightCm(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $cm = (int) round((float) $value);
        if ($cm < 1) {
            return '';
        }

        return HeightDisplay::formatCm($cm);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatBloodGroupId(mixed $value, array $context, ?MatrimonyProfile $profile): string
    {
        $id = $this->resolveNumericId($value, $context['blood_group_id'] ?? $context['blood_group'] ?? null);
        if ($id === null && $profile?->bloodGroup) {
            return $this->bloodGroupLabel($profile->bloodGroup);
        }
        if ($id === null) {
            $text = is_scalar($value) ? trim((string) $value) : '';
            if ($text !== '' && ! is_numeric($text)) {
                return $text;
            }

            return '';
        }
        $row = MasterBloodGroup::find($id);

        return $row ? $this->bloodGroupLabel($row) : '';
    }

    private function formatComplexionId(mixed $value): string
    {
        $id = is_numeric($value) ? (int) $value : null;
        if ($id === null || $id < 1) {
            return is_scalar($value) && ! is_numeric($value) ? trim((string) $value) : '';
        }
        $row = MasterComplexion::find($id);
        if (! $row) {
            return '';
        }
        $key = $row->key ?? null;
        if ($key) {
            $tKey = 'components.options.complexion.'.$key;
            $t = __($tKey);
            if ($t !== $tKey) {
                return $t;
            }
        }

        return BilingualMasterLabel::preferred($row->label_mr ?? null, $row->label_en ?? null, $row->label ?? null);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatReligionId(mixed $value, array $context, ?MatrimonyProfile $profile): string
    {
        if ($profile?->religion) {
            return BilingualMasterLabel::preferred(
                $profile->religion->label_mr,
                $profile->religion->label_en,
                $profile->religion->label
            );
        }
        $text = trim((string) ($context['religion'] ?? ''));
        if ($text !== '' && ! is_numeric($text)) {
            return $text;
        }
        $id = $this->resolveNumericId($value, $context['religion_id'] ?? null);
        $r = $id ? Religion::find($id) : null;

        return $r ? BilingualMasterLabel::preferred($r->label_mr, $r->label_en, $r->label) : (is_scalar($value) ? trim((string) $value) : '');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatCasteId(mixed $value, array $context, ?MatrimonyProfile $profile): string
    {
        if ($profile?->caste) {
            return BilingualMasterLabel::preferred(
                $profile->caste->label_mr,
                $profile->caste->label_en,
                $profile->caste->label
            );
        }
        $text = trim((string) ($context['caste'] ?? ''));
        if ($text !== '' && ! is_numeric($text)) {
            return $text;
        }
        $id = $this->resolveNumericId($value, $context['caste_id'] ?? null);
        $c = $id ? Caste::find($id) : null;

        return $c ? BilingualMasterLabel::preferred($c->label_mr, $c->label_en, $c->label) : (is_scalar($value) ? trim((string) $value) : '');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatSubCasteId(mixed $value, array $context, ?MatrimonyProfile $profile): string
    {
        if ($profile?->subCaste) {
            return BilingualMasterLabel::preferred(
                $profile->subCaste->label_mr,
                $profile->subCaste->label_en,
                $profile->subCaste->label
            );
        }
        $text = trim((string) ($context['sub_caste'] ?? ''));
        if ($text !== '' && ! is_numeric($text)) {
            return $text;
        }
        $id = $this->resolveNumericId($value, $context['sub_caste_id'] ?? null);
        $s = $id ? SubCaste::find($id) : null;

        return $s ? BilingualMasterLabel::preferred($s->label_mr, $s->label_en, $s->label) : (is_scalar($value) ? trim((string) $value) : '');
    }

    private function formatMaritalStatusId(mixed $value): string
    {
        $id = is_numeric($value) ? (int) $value : null;
        if ($id === null || $id < 1) {
            return is_scalar($value) && ! is_numeric($value) ? trim((string) $value) : '';
        }
        $ms = MasterMaritalStatus::find($id);
        if (! $ms) {
            return '';
        }
        $key = $ms->key ?? null;
        if ($key) {
            $tKey = 'components.options.marital_status.'.$key;
            $t = __($tKey);
            if ($t !== $tKey) {
                return $t;
            }
        }

        return BilingualMasterLabel::preferred($ms->label_mr ?? null, $ms->label ?? null, null);
    }

    private function bloodGroupLabel(MasterBloodGroup $row): string
    {
        $key = $row->key ?? null;
        if ($key) {
            $tKey = 'components.options.blood_group.'.$key;
            $t = __($tKey);
            if ($t !== $tKey) {
                return $t;
            }
            $tEn = \Illuminate\Support\Facades\Lang::get($tKey, [], 'en');
            if ($tEn !== $tKey && app()->getLocale() !== 'en') {
                return $t;
            }
        }

        return (string) ($row->label ?? $key ?? '');
    }

    private function resolveNumericId(mixed $primary, mixed $fallback = null): ?int
    {
        if (is_numeric($primary) && (int) $primary > 0) {
            return (int) $primary;
        }
        if (is_numeric($fallback) && (int) $fallback > 0) {
            return (int) $fallback;
        }

        return null;
    }
}
