<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakCommissionAgreement;
use App\Models\SuchakCustomerContext;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCollaborationService;
use App\Modules\Suchak\Services\SuchakCustomerListService;
use App\Services\ProfileSectionReadinessService;
use App\Support\Suchak\SuchakLocalizedText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SuchakCustomerDetailApiController extends Controller
{
    public function show(
        Request $request,
        int $representation,
        SuchakCustomerListService $customerListService,
        ProfileSectionReadinessService $readinessService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        /** @var SuchakAccount|null $account */
        $account = $user->suchakAccount;
        if ($account === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        $rows = $customerListService->rowsForAccount($account);
        foreach ($rows as $row) {
            if ((int) ($row['representation_id'] ?? 0) === $representation) {
                $sortAt = $row['sort_at'] ?? null;

                return response()->json([
                    'success' => true,
                    'message' => 'Customer detail loaded.',
                    'data' => [
                        'customer' => [
                            'row_key' => $row['row_key'] ?? null,
                            'kind' => $row['kind'] ?? null,
                            'profile_id' => $row['profile_id'] ?? null,
                            'representation_id' => $row['representation_id'] ?? null,
                            // D20's trail hangs off the CUSTOMER CONTEXT, not
                            // the representation, and until now that id reached
                            // a phone only inside the payment-request options —
                            // so opening a family's history would have cost a
                            // second, unrelated round trip to a money endpoint.
                            // Null when this customer has no context yet, which
                            // is a real state: the history is then genuinely
                            // empty, not merely unreachable.
                            'customer_context_id' => SuchakCustomerContext::query()
                                ->where('suchak_account_id', $account->id)
                                ->where('representation_id', $representation)
                                ->value('id'),
                            'intake_id' => $row['intake_id'] ?? null,
                            'photo_url' => $row['photo_url'] ?? null,
                            'name' => $row['name'] ?? null,
                            'age' => $row['age'] ?? null,
                            'gender' => $row['gender'] ?? null,
                            'address' => $row['address'] ?? null,
                            'status_label' => $row['status_label'] ?? null,
                            'consent_label' => $row['consent_label'] ?? null,
                            'consent_status' => $row['consent_status'] ?? null,
                            'has_pending_consent' => (bool) ($row['has_pending_consent'] ?? false),
                            'pending_consent_id' => $row['pending_consent_id'] ?? null,
                            'has_active_consent' => (bool) ($row['has_active_consent'] ?? false),
                            'can_request_consent' => (bool) ($row['can_request_consent'] ?? false),
                            'can_renew_consent' => (bool) ($row['can_renew_consent'] ?? false),
                            'default_consent_mobile' => $row['default_consent_mobile'] ?? null,
                            'default_consent_giver_name' => $row['default_consent_giver_name'] ?? null,
                            'lifecycle_label' => $row['lifecycle_label'] ?? null,
                            'paid' => $row['paid'] ?? null,
                            'consent_history' => $this->consentHistory($account, $representation),
                            // What is still worth filling in, section by
                            // section, so the Suchak knows what to ask BEFORE
                            // they dial the number below it on the screen.
                            // Identical block to the one the edit hub reads
                            // (`GET /suchak/nxt/{representation}/profile`) —
                            // one service, so the card and the edit screen it
                            // opens always print the same counts.
                            'readiness' => $readinessService->forProfile(
                                $this->profileForRow($row)
                            ),
                            'view_url' => $row['view_url'] ?? null,
                            'edit_url' => $row['edit_url'] ?? null,
                            'manage_url' => $row['manage_url'] ?? null,
                            'review_url' => $row['review_url'] ?? null,
                            'sort_at' => $sortAt instanceof \Illuminate\Support\Carbon
                                ? $sortAt->toIso8601String()
                                : null,
                        ],
                    ],
                ]);
            }
        }

        return response()->json(['success' => false, 'message' => 'Customer not found for this Suchak account.'], 404);
    }

    /**
     * The candidate profile behind a customer row, or null for a row that has
     * no profile yet (a scanned biodata awaiting conversion). The row already
     * carries `profile_id` as the server's own signal for that, so no second
     * lookup rule is invented here.
     *
     * @param  array<string, mixed>  $row
     */
    private function profileForRow(array $row): ?MatrimonyProfile
    {
        $profileId = $row['profile_id'] ?? null;

        return $profileId === null
            ? null
            : MatrimonyProfile::query()->find((int) $profileId);
    }

    /**
     * Read-only consent audit trail for a representation — every number the
     * Suchak sent a consent request to, with its status and time. There is no
     * app path to edit or delete it: consents are only cancelled (a status
     * change), never removed, so the record stays transparent and tamper-proof.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consentHistory(SuchakAccount $account, int $representation): array
    {
        $rep = $account->profileRepresentations()
            ->with(['consents'])
            ->find($representation);

        if ($rep === null) {
            return [];
        }

        return $rep->consents
            ->sortByDesc('created_at')
            ->take(20)
            ->map(static fn ($consent): array => [
                'mobile' => $consent->intended_mobile ?: $consent->consent_mobile_number,
                'giver_name' => $consent->consent_given_by_name,
                'status' => $consent->consent_status,
                'status_label' => SuchakLocalizedText::labelOrNull($consent->consent_status, 'consent')
                    ?? (string) __('suchak.labels.unknown'),
                'requested_at' => $consent->created_at instanceof \Illuminate\Support\Carbon
                    ? $consent->created_at->toIso8601String()
                    : null,
            ])
            ->values()
            ->all();
    }
}

