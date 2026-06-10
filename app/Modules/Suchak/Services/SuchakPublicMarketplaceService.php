<?php

namespace App\Modules\Suchak\Services;

use App\Models\Caste;
use App\Models\Location;
use App\Models\MatrimonyProfile;
use App\Models\Religion;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakServicePackage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SuchakPublicMarketplaceService
{
    public function __construct(
        private readonly SuchakCandidateMaskingService $maskingService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{district_id: int|null, taluka_id: int|null, religion_id: int|null, caste_id: int|null, service: string}
     */
    public function filtersFromInput(array $input): array
    {
        return [
            'district_id' => $this->nullablePositiveId($input['district_id'] ?? null),
            'taluka_id' => $this->nullablePositiveId($input['taluka_id'] ?? null),
            'religion_id' => $this->nullablePositiveId($input['religion_id'] ?? null),
            'caste_id' => $this->nullablePositiveId($input['caste_id'] ?? null),
            'service' => $this->limitedSearchText($input['service'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = $this->publicAccountQuery()
            ->with($this->accountRelations())
            ->withCount([
                'profileRepresentations as public_representations_count' => function (Builder $query): void {
                    $this->publicRepresentationScope($query);
                },
            ]);

        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('verified_at')
            ->orderBy('suchak_name')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (SuchakAccount $account): array => $this->accountCard($account, false));
    }

    /**
     * @return array<string, Collection<int, array{id: int, label: string}>>
     */
    public function filterOptions(): array
    {
        return [
            'districts' => $this->locationOptions('district_id'),
            'talukas' => $this->locationOptions('taluka_id'),
            'religions' => $this->religionOptions(),
            'castes' => $this->casteOptions(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publicProfile(SuchakAccount $account): ?array
    {
        $fresh = $this->publicAccountQuery()
            ->with($this->accountRelations())
            ->withCount([
                'profileRepresentations as public_representations_count' => function (Builder $query): void {
                    $this->publicRepresentationScope($query);
                },
            ])
            ->whereKey($account->id)
            ->first();

        return $fresh instanceof SuchakAccount
            ? $this->accountCard($fresh, true)
            : null;
    }

    /**
     * @return Builder<SuchakAccount>
     */
    private function publicAccountQuery(): Builder
    {
        return SuchakAccount::query()
            ->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)
            ->where('public_status', SuchakAccount::PUBLIC_ACTIVE)
            ->whereNull('rejected_at')
            ->whereNull('suspended_at')
            ->whereNull('archived_at');
    }

    /**
     * @return array<string, mixed>
     */
    private function accountRelations(): array
    {
        return [
            'cityLocation',
            'talukaLocation',
            'districtLocation',
            'stateLocation',
            'servicePackages' => function ($query): void {
                $this->publicPackageScope($query)
                    ->with(['stages', 'deliverables'])
                    ->orderByRaw('price_amount IS NULL')
                    ->orderBy('price_amount')
                    ->orderBy('package_name');
            },
            'profileRepresentations' => function ($query): void {
                $this->publicRepresentationScope($query)
                    ->with([
                        'matrimonyProfile.gender',
                        'matrimonyProfile.maritalStatus',
                        'matrimonyProfile.religion',
                        'matrimonyProfile.caste',
                        'matrimonyProfile.location.parent.parent.parent',
                        'matrimonyProfile.occupationMaster',
                    ])
                    ->orderByDesc('first_verified_consent_at')
                    ->orderByDesc('id');
            },
        ];
    }

    /**
     * @param  Builder<SuchakAccount>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $districtId = (int) ($filters['district_id'] ?? 0);
        if ($districtId > 0) {
            $query->where('district_id', $districtId);
        }

        $talukaId = (int) ($filters['taluka_id'] ?? 0);
        if ($talukaId > 0) {
            $query->where('taluka_id', $talukaId);
        }

        $religionId = (int) ($filters['religion_id'] ?? 0);
        $casteId = (int) ($filters['caste_id'] ?? 0);
        if ($religionId > 0 || $casteId > 0) {
            $query->whereHas('profileRepresentations', function (Builder $query) use ($religionId, $casteId): void {
                $this->publicRepresentationScope($query)
                    ->whereHas('matrimonyProfile', function (Builder $profileQuery) use ($religionId, $casteId): void {
                        $this->activeProfileScope($profileQuery);

                        if ($religionId > 0) {
                            $profileQuery->where('religion_id', $religionId);
                        }

                        if ($casteId > 0) {
                            $profileQuery->where('caste_id', $casteId);
                        }
                    });
            });
        }

        $service = trim((string) ($filters['service'] ?? ''));
        if ($service !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $service).'%';
            $query->whereHas('servicePackages', function (Builder $query) use ($like): void {
                $this->publicPackageScope($query)
                    ->where(function (Builder $query) use ($like): void {
                        $query
                            ->where('package_name', 'like', $like)
                            ->orWhere('package_description', 'like', $like)
                            ->orWhereHas('stages', function (Builder $query) use ($like): void {
                                $query
                                    ->where('stage_name', 'like', $like)
                                    ->orWhere('stage_description', 'like', $like);
                            })
                            ->orWhereHas('deliverables', function (Builder $query) use ($like): void {
                                $query
                                    ->where('deliverable_name', 'like', $like)
                                    ->orWhere('deliverable_description', 'like', $like);
                            });
                    });
            });
        }
    }

    private function publicRepresentationScope($query)
    {
        return $query
            ->publiclyRoutable()
            ->whereHas('matrimonyProfile', function (Builder $query): void {
                $this->activeProfileScope($query);
            });
    }

    /**
     * @param  Builder<MatrimonyProfile>  $query
     */
    private function activeProfileScope(Builder $query): Builder
    {
        return $query
            ->where('lifecycle_state', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('is_suspended')->orWhere('is_suspended', false);
            });
    }

    private function publicPackageScope($query)
    {
        return $query->where('package_status', SuchakServicePackage::STATUS_PUBLISHED);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountCard(SuchakAccount $account, bool $includeDetail): array
    {
        $packages = $account->servicePackages
            ->filter(fn (SuchakServicePackage $package): bool => ! $this->hasPublicClaimRisk([
                $package->package_name,
                $package->package_description,
            ]))
            ->values();

        $representations = $account->profileRepresentations
            ->filter(fn (SuchakProfileRepresentation $representation): bool => $representation->matrimonyProfile instanceof MatrimonyProfile)
            ->values();

        return [
            'account' => [
                'id' => (int) $account->id,
                'name' => $this->safePublicText($account->suchak_name) ?: 'Verified Suchak',
                'office_name' => $this->safePublicText($account->office_name),
                'business_type' => Str::headline((string) $account->business_type),
                'verified_badge' => 'Verified Suchak',
                'verified_at' => $account->verified_at?->toDateString(),
                'public_profile_url' => route('suchak.marketplace.show', $account),
            ],
            'area' => [
                'line' => $this->areaLine($account),
                'district' => $account->districtLocation?->localizedName(),
                'taluka' => $account->talukaLocation?->localizedName(),
                'city' => $account->cityLocation?->localizedName(),
            ],
            'communities' => $this->communitySummaries($representations),
            'metrics' => [
                'public_representations_count' => (int) ($account->public_representations_count ?? $representations->count()),
                'published_service_count' => $packages->count(),
            ],
            'packages' => $packages
                ->map(fn (SuchakServicePackage $package): array => $this->packageCard($package))
                ->take($includeDetail ? 6 : 3)
                ->values(),
            'representations' => $includeDetail
                ? $representations
                    ->map(fn (SuchakProfileRepresentation $representation): array => $this->representationCard($representation))
                    ->take(8)
                    ->values()
                : collect(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packageCard(SuchakServicePackage $package): array
    {
        return [
            'id' => (int) $package->id,
            'name' => $this->safePublicText($package->package_name) ?: 'Published service package',
            'description' => $this->safePublicText($package->package_description),
            'price_label' => $this->priceLabel($package),
            'stage_count' => $package->stages->count(),
            'deliverable_count' => $package->deliverables->count(),
            'stages' => $package->stages
                ->map(fn ($stage): string => $this->safePublicText($stage->stage_name) ?: 'Service stage')
                ->take(4)
                ->values(),
            'deliverables' => $package->deliverables
                ->map(fn ($deliverable): string => $this->safePublicText($deliverable->deliverable_name) ?: 'Service deliverable')
                ->take(4)
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function representationCard(SuchakProfileRepresentation $representation): array
    {
        /** @var MatrimonyProfile $profile */
        $profile = $representation->matrimonyProfile;
        $masked = $this->maskingService->maskedSummary($profile, $representation);

        return array_merge($masked, [
            'target_profile_id' => (int) $profile->id,
            'request_route' => route('matrimony.profile.suchak-requests.store', [$profile, $representation]),
        ]);
    }

    private function areaLine(SuchakAccount $account): string
    {
        $parts = collect([
            $account->cityLocation?->localizedName(),
            $account->talukaLocation?->localizedName(),
            $account->districtLocation?->localizedName(),
        ])
            ->filter(fn (?string $value): bool => $value !== null && trim($value) !== '')
            ->unique()
            ->values();

        return $parts->isNotEmpty()
            ? $parts->implode(', ')
            : 'Area not publicly listed';
    }

    /**
     * @param  Collection<int, SuchakProfileRepresentation>  $representations
     * @return Collection<int, string>
     */
    private function communitySummaries(Collection $representations): Collection
    {
        return $representations
            ->map(function (SuchakProfileRepresentation $representation): ?string {
                $profile = $representation->matrimonyProfile;
                if (! $profile instanceof MatrimonyProfile) {
                    return null;
                }

                $parts = collect([
                    $profile->religion?->display_label,
                    $profile->caste?->display_label,
                ])
                    ->filter(fn (?string $value): bool => $value !== null && trim($value) !== '')
                    ->values();

                return $parts->isEmpty() ? null : $parts->implode(' / ');
            })
            ->filter()
            ->unique()
            ->take(6)
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, label: string}>
     */
    private function locationOptions(string $field): Collection
    {
        $ids = $this->publicAccountQuery()
            ->whereNotNull($field)
            ->distinct()
            ->pluck($field)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Location::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location): array => [
                'id' => (int) $location->id,
                'label' => $location->localizedName(),
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, label: string}>
     */
    private function religionOptions(): Collection
    {
        $ids = $this->publicRepresentationJoinQuery()
            ->whereNotNull('matrimony_profiles.religion_id')
            ->distinct()
            ->pluck('matrimony_profiles.religion_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Religion::query()
            ->whereIn('id', $ids)
            ->orderBy('label')
            ->get()
            ->map(fn (Religion $religion): array => [
                'id' => (int) $religion->id,
                'label' => $religion->display_label,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, label: string}>
     */
    private function casteOptions(): Collection
    {
        $ids = $this->publicRepresentationJoinQuery()
            ->whereNotNull('matrimony_profiles.caste_id')
            ->distinct()
            ->pluck('matrimony_profiles.caste_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Caste::query()
            ->whereIn('id', $ids)
            ->orderBy('label')
            ->get()
            ->map(fn (Caste $caste): array => [
                'id' => (int) $caste->id,
                'label' => $caste->display_label,
            ])
            ->values();
    }

    /**
     * @return Builder<SuchakProfileRepresentation>
     */
    private function publicRepresentationJoinQuery(): Builder
    {
        return SuchakProfileRepresentation::query()
            ->publiclyRoutable()
            ->join('matrimony_profiles', 'matrimony_profiles.id', '=', 'suchak_profile_representations.matrimony_profile_id')
            ->where('matrimony_profiles.lifecycle_state', 'active')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('matrimony_profiles.is_suspended')
                    ->orWhere('matrimony_profiles.is_suspended', false);
            });
    }

    private function priceLabel(SuchakServicePackage $package): string
    {
        if (! is_numeric($package->price_amount)) {
            return 'Price shared through platform request';
        }

        $amount = number_format((float) $package->price_amount, 0);
        $currency = strtoupper((string) ($package->currency ?: 'INR'));

        return $currency.' '.$amount;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function hasPublicClaimRisk(array $values): bool
    {
        foreach ($values as $value) {
            $text = Str::lower(trim((string) ($value ?? '')));
            if ($text === '') {
                continue;
            }

            if (preg_match('/(100\s*(%|percent|टक्के))|guarantee|guaranteed|rating|stars?|best|no\.?\s*1|number\s*1|top\s*rated|success\s*(rate|claim)?|sure\s*shot|confirmed\s+(marriage|match)|assured\s+(marriage|match|success)|(marriage|match|success)\s+assured|हमी|खात्रीशीर/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function safePublicText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '' || $this->hasPublicClaimRisk([$normalized])) {
            return null;
        }

        return Str::limit($normalized, 240, '');
    }

    private function limitedSearchText(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? '' : Str::limit($normalized, 80, '');
    }

    private function nullablePositiveId(mixed $value): ?int
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        $id = (int) $normalized;

        return $id > 0 ? $id : null;
    }
}
