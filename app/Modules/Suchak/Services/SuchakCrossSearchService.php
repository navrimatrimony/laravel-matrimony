<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class SuchakCrossSearchService
{
    public function __construct(
        private readonly SuchakCandidateMaskingService $maskingService,
        private readonly SuchakAccessService $accessService,
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

        $query = SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount',
                'matrimonyProfile.gender',
                'matrimonyProfile.maritalStatus',
                'matrimonyProfile.religion',
                'matrimonyProfile.caste',
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
            ->through(function (SuchakProfileRepresentation $representation): array {
                /** @var MatrimonyProfile $profile */
                $profile = $representation->matrimonyProfile;

                return $this->maskingService->maskedSummary($profile, $representation);
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

                return $this->maskingService->maskedSummary($profile, $representation);
            });
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyProfileFilters(Builder $query, array $filters): void
    {
        $query
            ->where('lifecycle_state', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('is_suspended')->orWhere('is_suspended', false);
            });

        $genderId = (int) ($filters['gender_id'] ?? 0);
        if ($genderId > 0) {
            $query->where('gender_id', $genderId);
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
}
