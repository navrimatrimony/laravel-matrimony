<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakProfileRepresentation;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCandidateProposalInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The per-candidate proposal inbox (blueprint phase 5, §16 "per-candidate proposal inbox").
 *
 * `GET /api/v1/suchak/marketplace/candidates/{representation}/proposals`
 *
 * ONE of the caller's candidates, and every proposal anyone has made against him across every
 * challenge the caller published for him. The sibling door
 * `GET /marketplace/challenges/{challenge}/proposals` reads the same rows on the other axis — one
 * challenge, all its answers — and is still the right door for accepting or rejecting. This one is
 * for deciding, which is a comparison, and a comparison cannot be made across two screens.
 *
 * ── WHAT THE QUERY STRING MAY AND MAY NOT DO ────────────────────────────────────────────────────
 *
 * Every row is another Suchak's candidate, so every filter is a question about a value that may be
 * hidden. The rules are not restated here — they belong to
 * `SuchakCrossSearchService::OWN_BOOK_ONLY_FILTERS` and
 * `CROSS_SEARCH_NARROWEST_FILTERABLE_HIERARCHY`, and the service routes every filter through the
 * one owner that enforces them. What this controller does about them is narrower and worth stating:
 * `name`, `income_min` and `income_max` are NOT DECLARED in the rules below, so `validate()` never
 * returns them and they never reach the service at all. That is belt-and-braces on top of the
 * owner's silent refusal, and it is silent for the owner's reason — a 422 naming the refused filter
 * would confirm to a prober exactly which values are worth probing for.
 *
 * `district_id` and `taluka_id` ARE accepted, because the masked card already prints both; an id
 * below the taluka is refused by the owner and narrows nothing, again silently.
 *
 * `sort` is the other half of the same question — an ordering is a comparison — and its allow-list
 * is the service's `SORTS`.
 */
class SuchakCandidateProposalInboxApiController extends Controller
{
    /**
     * Query: `status` (a `suchak_collaboration_requests` status), `challenge_id` (one of THIS
     * candidate's challenges; anything else narrows nothing), `sort` (see
     * SuchakCandidateProposalInboxService::SORTS, default `recent`), `q` (education / occupation
     * free text — the SAME meaning it has on `GET /suchak/search`), `education`, `age_min`,
     * `age_max`, `gender_id`, `caste_id`, `religion_id`, `marital_status_id`, `district_id`,
     * `taluka_id`, `page`, `per_page` (1–50, default 20).
     *
     * 200: `{ success, data: { candidate, totals, challenges, proposals, meta } }`.
     * 404 when the representation is not the caller's — 404 and not 403, so a Suchak never learns
     * that another Suchak's representation exists by the shape of the refusal.
     * 422 when the caller does not hold the marketplace badge (D18).
     */
    public function __invoke(
        Request $request,
        SuchakProfileRepresentation $representation,
        SuchakCandidateProposalInboxService $inboxService,
    ): JsonResponse {
        $user = $this->suchakUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(SuchakCollaborationRequest::STATUSES)],
            'challenge_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', Rule::in(SuchakCandidateProposalInboxService::SORTS)],
            'q' => ['nullable', 'string', 'max:80'],
            'education' => ['nullable', 'string', 'max:80'],
            'age_min' => ['nullable', 'integer', 'min:18', 'max:100'],
            'age_max' => ['nullable', 'integer', 'min:18', 'max:100'],
            'gender_id' => ['nullable', 'integer', 'min:1'],
            'caste_id' => ['nullable', 'integer', 'min:1'],
            'religion_id' => ['nullable', 'integer', 'min:1'],
            'marital_status_id' => ['nullable', 'integer', 'min:1'],
            'district_id' => ['nullable', 'integer', 'min:1'],
            'taluka_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        // The ownership refusal is made HERE as a 404 and again inside the service as a Marathi
        // 422. Two answers to one question on purpose: the 404 is the privacy answer (a stranger
        // never learns the row exists), and the service's is the RULE, so a second entrance that
        // skips this controller still cannot read another Suchak's candidate's inbox.
        if ((int) $representation->suchak_account_id !== (int) $user->suchakAccount->id) {
            return $this->error(__('suchak.api.errors.profile_not_found'), 404);
        }

        try {
            $inbox = $inboxService->inboxFor(
                $representation,
                $user->suchakAccount,
                $filters,
                (int) ($filters['per_page'] ?? 20),
                max(1, (int) ($filters['page'] ?? 1)),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return response()->json([
            'success' => true,
            'data' => $inbox,
        ]);
    }

    private function suchakUser(Request $request): User|JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return $this->error(__('suchak.api.errors.suchak_account_required'), 403);
        }

        return $user;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
