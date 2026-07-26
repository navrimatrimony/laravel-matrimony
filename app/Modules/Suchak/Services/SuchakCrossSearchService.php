<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SuchakCrossSearchService
{
    public function __construct(
        private readonly SuchakCandidateMaskingService $maskingService,
        private readonly SuchakAccessService $accessService,
        private readonly SuchakMatchFitService $matchFitService,
    ) {
    }

    public function canSearch(SuchakAccount $account): bool
    {
        return $this->accessService->canOperate($account);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(SuchakAccount $actorAccount, array $filters = []): LengthAwarePaginator
    {
        $actorAccount->refresh();
        $this->accessService->assertCanOperate(
            $actorAccount,
            'Only verified Suchak accounts can use masked search.',
        );
        $selectedOwnRepresentation = $this->selectedOwnRepresentation($actorAccount, $filters);

        $query = SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.visibilitySetting',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->publiclyRoutable()
            ->where('suchak_account_id', '!=', (int) $actorAccount->id)
            ->whereHas('matrimonyProfile', function (Builder $query) use ($filters): void {
                $this->applyProfileFilters($query, $filters);
            })
            ->orderByDesc('first_verified_consent_at')
            ->orderByDesc('id');

        return $query
            ->paginate(12)
            ->withQueryString()
            ->through(function (SuchakProfileRepresentation $representation) use ($selectedOwnRepresentation): array {
                /** @var MatrimonyProfile $profile */
                $profile = $representation->matrimonyProfile;
                $summary = $this->maskingService->maskedSummary($profile, $representation);
                $suchakName = trim((string) ($representation->suchakAccount?->suchak_name ?: 'Public Suchak'));
                $summary['target_suchak_label'] = '#'.$representation->suchak_account_id.' '.Str::limit($suchakName, 80, '');
                $summary = array_merge($summary, $this->fitSummary($selectedOwnRepresentation, $representation));

                return $summary;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function ownRepresentationOptions(SuchakAccount $actorAccount): Collection
    {
        return SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.visibilitySetting',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->withValidConsent()
            ->where('suchak_account_id', (int) $actorAccount->id)
            ->whereHas('matrimonyProfile', function (Builder $query): void {
                $query
                    ->where('lifecycle_state', 'active')
                    ->where(function (Builder $query): void {
                        $query->whereNull('is_suspended')->orWhere('is_suspended', false);
                    });
            })
            ->orderByDesc('first_verified_consent_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (SuchakProfileRepresentation $representation): array {
                /** @var MatrimonyProfile $profile */
                $profile = $representation->matrimonyProfile;
                $summary = $this->maskingService->maskedSummary($profile, $representation);
                $summary['own_profile'] = $this->ownRepresentationOptionMeta($profile, $summary);
                $summary['option_label'] = $this->ownRepresentationOptionLabel($profile, $summary);

                return $summary;
            });
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyProfileFilters(Builder $query, array $filters): void
    {
        $this->applyActiveProfileScope($query);

        $genderId = (int) ($filters['gender_id'] ?? 0);
        if ($genderId > 0) {
            $query->where('gender_id', $genderId);
        }

        $casteId = (int) ($filters['caste_id'] ?? 0);
        if ($casteId > 0) {
            $query->where('caste_id', $casteId);
        }

        $religionId = (int) ($filters['religion_id'] ?? 0);
        if ($religionId > 0) {
            $query->where('religion_id', $religionId);
        }

        $maritalStatusId = (int) ($filters['marital_status_id'] ?? 0);
        if ($maritalStatusId > 0) {
            $query->where('marital_status_id', $maritalStatusId);
        }

        $ageMin = (int) ($filters['age_min'] ?? 0);
        if ($ageMin >= 18) {
            $query->whereDate('date_of_birth', '<=', Carbon::now()->subYears($ageMin)->toDateString());
        }

        $ageMax = (int) ($filters['age_max'] ?? 0);
        if ($ageMax >= 18) {
            $query->whereDate('date_of_birth', '>=', Carbon::now()->subYears($ageMax + 1)->addDay()->toDateString());
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(function (Builder $query) use ($like): void {
                $query
                    ->where('highest_education', 'like', $like)
                    ->orWhereHas('occupationMaster', function (Builder $query) use ($like): void {
                        $query->where('name', 'like', $like);
                    });
            });
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function selectedOwnRepresentation(SuchakAccount $actorAccount, array $filters): ?SuchakProfileRepresentation
    {
        $selectedId = (int) ($filters['requesting_representation_id'] ?? 0);
        if ($selectedId <= 0) {
            return null;
        }

        return SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
                'matrimonyProfile.visibilitySetting',
                'matrimonyProfile.location.parent.parent.parent',
                'matrimonyProfile.occupationMaster',
            ])
            ->withValidConsent()
            ->whereKey($selectedId)
            ->where('suchak_account_id', (int) $actorAccount->id)
            ->whereHas('matrimonyProfile', function (Builder $query): void {
                $this->applyActiveProfileScope($query);
            })
            ->first();
    }

    /**
     * Delegates entirely to {@see SuchakMatchFitService} — i.e. to the real matching engine. The key
     * set is unchanged so the existing search UI keeps rendering; the values are now the same weighted
     * score, field breakdown and explain reasons that members get.
     *
     * @return array{reasons: array<int, string>, warnings: array<int, string>, fit_label: string, fit_summary: string}
     */
    private function fitSummary(
        ?SuchakProfileRepresentation $selectedOwnRepresentation,
        SuchakProfileRepresentation $candidateRepresentation,
    ): array {
        if (! $selectedOwnRepresentation instanceof SuchakProfileRepresentation) {
            return [
                'reasons' => [],
                'warnings' => [],
                'fit_label' => 'Select your side profile',
                'fit_summary' => 'Select your represented profile above to compare fit signals.',
            ];
        }

        $ownProfile = $selectedOwnRepresentation->matrimonyProfile;
        $candidateProfile = $candidateRepresentation->matrimonyProfile;
        if (! $ownProfile instanceof MatrimonyProfile || ! $candidateProfile instanceof MatrimonyProfile) {
            return [
                'reasons' => [],
                'warnings' => ['Profile summary unavailable for comparison.'],
                'fit_label' => 'Review manually',
                'fit_summary' => 'Fit signals could not be calculated.',
            ];
        }

        $fit = $this->matchFitService->fit($ownProfile, $candidateProfile);
        if ($fit === null) {
            return [
                'reasons' => [],
                'warnings' => ['No compatible match signal found.'],
                'fit_label' => 'Review manually',
                'fit_summary' => 'The matching engine found no compatible fit for the selected profile.',
            ];
        }

        return [
            'reasons' => $fit['reasons'],
            'warnings' => $fit['warnings'],
            'fit_label' => $fit['fit_label'],
            'fit_summary' => $fit['fit_summary'],
            'match_score' => $fit['match_score'],
            'match_base_score' => $fit['match_base_score'],
            'match_field_points' => $fit['match_field_points'],
        ];
    }

    private function applyActiveProfileScope(Builder $query): void
    {
        $query
            ->where('lifecycle_state', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('is_suspended')->orWhere('is_suspended', false);
            });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function ownRepresentationOptionLabel(MatrimonyProfile $profile, array $summary): string
    {
        $community = collect([
            $summary['community']['religion'] ?? null,
            $summary['community']['caste'] ?? null,
        ])->filter()->implode(' / ');
        $location = collect([
            $summary['location']['city'] ?? null,
            $summary['location']['district'] ?? null,
        ])->filter()->implode(', ');

        $age = isset($summary['basic']['age_years'])
            ? $summary['basic']['age_years'].' years'
            : null;

        return collect([
            trim((string) ($profile->full_name ?? '')) ?: ($summary['basic']['gender'] ?? 'Represented profile'),
            $age,
            $summary['basic']['gender'] ?? null,
            $community !== '' ? $community : null,
            $location !== '' ? $location : null,
        ])->filter()->implode(' · ');
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function ownRepresentationOptionMeta(MatrimonyProfile $profile, array $summary): array
    {
        $location = collect([
            $summary['location']['city'] ?? null,
            $summary['location']['district'] ?? null,
        ])->filter()->implode(', ');

        $educationJob = collect([
            $summary['education']['highest'] ?? null,
            $summary['occupation']['broad'] ?? null,
        ])->filter()->implode(' / ');

        $age = isset($summary['basic']['age_years'])
            ? $summary['basic']['age_years'].' years'
            : null;

        $name = trim((string) ($profile->full_name ?? ''));

        return [
            'name' => $name !== '' ? $name : 'Represented profile',
            'gender' => $summary['basic']['gender'] ?? null,
            'age' => $age,
            'location' => $location !== '' ? $location : null,
            'education_job' => $educationJob !== '' ? $educationJob : null,
            'photo_url' => $summary['photo']['url']
                ?? $summary['photo']['placeholder_url']
                ?? asset('images/placeholders/default-profile.svg'),
        ];
    }
}
