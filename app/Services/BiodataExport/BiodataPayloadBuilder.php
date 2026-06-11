<?php

namespace App\Services\BiodataExport;

use App\Models\FieldRegistry;
use App\Models\MatrimonyProfile;
use App\Services\ExtendedFieldService;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\ProfileShowSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BiodataPayloadBuilder
{
    private ?string $photoDataUri = null;

    public function __construct(
        private ProfileShowSnapshotService $snapshotService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(MatrimonyProfile $profile): array
    {
        $profile->loadMissing(['user', 'gender', 'maritalStatus', 'city', 'district', 'state']);

        $extendedValues = Schema::hasTable('profile_extended_fields')
            ? ExtendedFieldService::getValuesForProfile($profile)
            : [];
        $extendedMeta = $this->extendedMeta($extendedValues);

        $sections = $this->snapshotService->build($profile, [
            'is_own_profile' => true,
            'date_of_birth_visible' => true,
            'marital_status_visible' => true,
            'education_visible' => true,
            'location_visible' => true,
            'caste_visible' => true,
            'height_visible' => true,
            'enable_relatives_section' => true,
            'preference_criteria' => $this->firstRow('profile_preference_criteria', (int) $profile->id),
            'preferred_religion_ids' => $this->pluckIds('profile_preferred_religions', 'religion_id', (int) $profile->id),
            'preferred_caste_ids' => $this->pluckIds('profile_preferred_castes', 'caste_id', (int) $profile->id),
            'preferred_district_ids' => $this->pluckIds('profile_preferred_districts', 'district_id', (int) $profile->id),
            'preferred_education_degree_ids' => $this->pluckIds('profile_preferred_education_degrees', 'education_degree_id', (int) $profile->id),
            'preferred_occupation_master_ids' => $this->pluckIds('profile_preferred_occupation_master', 'occupation_master_id', (int) $profile->id),
            'preferred_diet_ids' => $this->pluckIds('profile_preferred_diets', 'diet_id', (int) $profile->id),
            'preferred_marital_status_ids' => $this->pluckIds('profile_preferred_marital_statuses', 'marital_status_id', (int) $profile->id),
            'extended_attributes' => $this->firstRow('profile_extended_attributes', (int) $profile->id),
            'extended_values' => $extendedValues,
            'extended_meta' => $extendedMeta,
        ]);

        $this->photoDataUri = $this->photoDataUri($profile);

        return [
            'profile_id' => (int) $profile->id,
            'title' => trim((string) ($profile->full_name ?? '')) ?: __('profile.biodata_export_title'),
            'headline' => $this->headline($profile),
            'generated_at' => now()->format('d M Y, h:i A'),
            'sections' => $this->filterSections($sections),
            'photo' => [
                'available' => $this->photoDataUri !== null,
                'data_uri' => $this->photoDataUri,
                'alt' => trim((string) ($profile->full_name ?? 'Profile photo')),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $extendedValues
     * @return array<string, string>
     */
    private function extendedMeta(array $extendedValues): array
    {
        if ($extendedValues === [] || ! Schema::hasTable('field_registry')) {
            return [];
        }

        return FieldRegistry::query()
            ->where('field_type', 'EXTENDED')
            ->whereIn('field_key', array_keys($extendedValues))
            ->pluck('display_label', 'field_key')
            ->map(fn ($label) => (string) $label)
            ->toArray();
    }

    private function firstRow(string $table, int $profileId): ?object
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)->where('profile_id', $profileId)->first();
    }

    /**
     * @return list<int>
     */
    private function pluckIds(string $table, string $column, int $profileId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->where('profile_id', $profileId)
            ->pluck($column)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function filterSections(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            $rows = collect($section['rows'] ?? [])
                ->filter(fn ($row) => trim((string) ($row['value'] ?? '')) !== '' || (bool) ($row['locked'] ?? false))
                ->values()
                ->all();
            $groups = collect($section['groups'] ?? [])
                ->map(function ($group) {
                    $lines = collect($group['lines'] ?? [])
                        ->map(fn ($line) => trim((string) $line))
                        ->filter()
                        ->values()
                        ->all();

                    return [
                        'heading' => trim((string) ($group['heading'] ?? '')),
                        'lines' => $lines,
                    ];
                })
                ->filter(fn ($group) => $group['lines'] !== [])
                ->values()
                ->all();
            $marriageBlocks = collect($section['marriage_blocks'] ?? [])
                ->map(fn ($block) => collect($block)->map(fn ($line) => trim((string) $line))->filter()->values()->all())
                ->filter()
                ->values()
                ->all();

            if ($rows === [] && $groups === [] && $marriageBlocks === []) {
                continue;
            }

            $section['rows'] = $rows;
            $section['groups'] = $groups;
            if ($marriageBlocks !== []) {
                $section['marriage_blocks'] = $marriageBlocks;
            }
            $out[] = $section;
        }

        return $out;
    }

    private function headline(MatrimonyProfile $profile): string
    {
        $parts = array_filter([
            trim((string) ($profile->highest_education ?? '')),
            trim((string) ($profile->occupation_title ?? '')),
            trim(implode(', ', array_filter([
                $profile->city?->name,
                $profile->district?->name,
                $profile->state?->name,
            ]))),
        ], fn ($part) => $part !== '');

        return implode(' | ', array_slice(array_values($parts), 0, 3));
    }

    private function photoDataUri(MatrimonyProfile $profile): ?string
    {
        if (($profile->photo_approved ?? null) === false) {
            return null;
        }

        $absolutePath = null;
        $primary = trim((string) ($profile->profile_photo ?? ''));
        if ($primary !== '') {
            if (ProfilePhotoUrlService::isPendingPlaceholder($primary)) {
                $absolutePath = ProfilePhotoUrlService::resolvePendingTempAbsolutePath($primary)
                    ?? ProfilePhotoUrlService::resolvePendingFallbackFromPrimaryGallery($profile);
            } else {
                $absolutePath = ProfilePhotoUrlService::resolveStoredPublicAbsolutePath($primary);
            }
        }

        if ($absolutePath === null) {
            $galleryPath = ProfilePhotoUrlService::primaryNonPendingGalleryRelativePath($profile);
            if ($galleryPath !== null) {
                $absolutePath = ProfilePhotoUrlService::resolveStoredPublicAbsolutePath($galleryPath);
            }
        }

        if ($absolutePath === null || ! is_file($absolutePath)) {
            return null;
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $absolutePath);
                finfo_close($finfo);
                if (is_string($detected) && str_starts_with($detected, 'image/')) {
                    $mime = $detected;
                }
            }
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
