<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakPaymentRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the Suchak "payment received" tracking screen.
 *
 * Reuses the existing {@see SuchakPaymentRequest} data (no tracking table):
 * every fact the screen needs — customer name + mobile, plan name, amount,
 * sent date, status, opened signal, paid signal — is reachable through the
 * request and its existing relations. All queries are strictly scoped to the
 * authenticated Suchak account; a client-supplied account id is never trusted.
 */
class SuchakPaymentRequestTrackingService
{
    public const FILTER_ALL = 'all';
    public const FILTER_PENDING = 'pending';
    public const FILTER_PAID = 'paid';

    public const FILTERS = [
        self::FILTER_ALL,
        self::FILTER_PENDING,
        self::FILTER_PAID,
    ];

    /**
     * Active-but-unpaid statuses: everything the Suchak still needs to collect.
     * Mirrors the "open requests" set surfaced on the payments ledger screen.
     *
     * @var array<int, string>
     */
    public const PENDING_STATUSES = [
        SuchakPaymentRequest::STATUS_SENT,
        SuchakPaymentRequest::STATUS_OPENED,
        SuchakPaymentRequest::STATUS_PENDING,
        SuchakPaymentRequest::STATUS_PARTIALLY_PAID,
        SuchakPaymentRequest::STATUS_OVERDUE,
    ];

    /**
     * @param  array{search?: ?string, filter?: ?string, per_page?: int|string|null, page?: int|string|null}  $params
     * @return array{
     *     payment_requests: list<array<string, mixed>>,
     *     summary: array{pending_count: int, total_amount_due: string, currency: string},
     *     pagination: array{current_page: int, per_page: int, total: int, last_page: int},
     * }
     */
    public function trackingFeed(SuchakAccount $account, array $params = []): array
    {
        $search = $this->normalizeSearch($params['search'] ?? null);
        $filter = $this->normalizeFilter($params['filter'] ?? null);
        $perPage = $this->normalizePerPage($params['per_page'] ?? null);

        $paginator = $this->baseQuery($account)
            ->when($search !== null, fn (Builder $query) => $this->applySearch($query, $search))
            ->when($filter === self::FILTER_PAID, fn (Builder $query) => $query->where('payment_status', SuchakPaymentRequest::STATUS_PAID))
            ->when($filter === self::FILTER_PENDING, fn (Builder $query) => $query->whereIn('payment_status', self::PENDING_STATUSES))
            ->latest('id')
            ->paginate($perPage);

        return [
            'payment_requests' => $paginator->getCollection()
                ->map(fn (SuchakPaymentRequest $request): array => $this->presentItem($request))
                ->values()
                ->all(),
            'summary' => $this->summary($account, $search),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Outstanding totals for the account, honouring the active search term but
     * independent of the paid/pending tab so the header always shows what is
     * still owed.
     *
     * @return array{pending_count: int, total_amount_due: string, currency: string}
     */
    private function summary(SuchakAccount $account, ?string $search): array
    {
        $pendingQuery = $this->baseQuery($account)
            ->when($search !== null, fn (Builder $query) => $this->applySearch($query, $search))
            ->whereIn('payment_status', self::PENDING_STATUSES);

        $pendingCount = (clone $pendingQuery)->count();
        $totalDue = (float) (clone $pendingQuery)->sum('amount_due');

        return [
            'pending_count' => $pendingCount,
            'total_amount_due' => number_format($totalDue, 2, '.', ''),
            'currency' => 'INR',
        ];
    }

    private function baseQuery(SuchakAccount $account): Builder
    {
        return SuchakPaymentRequest::query()
            ->where('suchak_account_id', $account->id)
            ->with([
                'customerContext.candidateProfile.user',
                'customerAgreement',
            ]);
    }

    /**
     * Match the search term against the customer's name (candidate profile name,
     * payer name, consent-giver name) or their mobile (primary profile contact or
     * the linked account mobile).
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        $like = '%'.$this->escapeLike($search).'%';

        return $query->whereHas('customerContext', function (Builder $context) use ($like): void {
            $context->where('payer_name', 'like', $like)
                ->orWhere('consent_giver_name', 'like', $like)
                ->orWhereHas('candidateProfile', function (Builder $profile) use ($like): void {
                    $profile->where('full_name', 'like', $like)
                        ->orWhereHas('user', fn (Builder $user) => $user->where('mobile', 'like', $like))
                        ->orWhereExists(function ($sub) use ($like): void {
                            $sub->select(DB::raw(1))
                                ->from('profile_contacts')
                                ->whereColumn('profile_contacts.profile_id', 'matrimony_profiles.id')
                                ->where('profile_contacts.phone_number', 'like', $like);
                        });
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(SuchakPaymentRequest $request): array
    {
        $context = $request->customerContext;
        $profile = $context?->candidateProfile;
        $agreement = $request->customerAgreement;

        $customerName = $this->firstNonEmpty([
            $profile?->full_name,
            $context?->payer_name,
            $context?->consent_giver_name,
        ]);

        return [
            'id' => $request->id,
            'customer_name' => $customerName,
            'customer_mobile' => $profile?->primary_contact_number,
            'plan_name' => $this->firstNonEmpty([
                $agreement?->package_name,
                $request->request_title,
            ]),
            'amount' => $request->amount_due,
            'currency' => $request->currency ?? 'INR',
            'status' => $request->payment_status,
            // "Opened?" — the customer viewed the secure link (SENT → OPENED flip).
            'opened' => $request->opened_at !== null,
            // "Paid?" — request settled in full.
            'paid' => $request->payment_status === SuchakPaymentRequest::STATUS_PAID,
            'sent_at' => $request->sent_at?->toIso8601String(),
            'opened_at' => $request->opened_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, ?string>  $candidates
     */
    private function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeSearch(mixed $value): ?string
    {
        $search = trim((string) ($value ?? ''));

        return $search === '' ? null : mb_substr($search, 0, 191);
    }

    private function normalizeFilter(mixed $value): string
    {
        $filter = trim((string) ($value ?? ''));

        return in_array($filter, self::FILTERS, true) ? $filter : self::FILTER_ALL;
    }

    private function normalizePerPage(mixed $value): int
    {
        $perPage = (int) ($value ?? 0);
        if ($perPage <= 0) {
            return 20;
        }

        return min($perPage, 100);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
