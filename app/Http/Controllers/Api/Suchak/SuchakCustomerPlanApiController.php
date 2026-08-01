<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakCustomerPlan;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakCustomerPlanService;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Per-Suchak REUSABLE customer plan presets (management + carousel resolution).
 *
 * Mounted under /suchak/customer-plans — deliberately NOT /suchak/plans, which
 * is the UNRELATED platform subscription catalog (SuchakBillingApiController)
 * consumed by the Suchak app. Same word, different meaning; never conflated.
 *
 * Every query is scoped to the authenticated Suchak account; a client-supplied
 * Suchak id is never trusted.
 */
class SuchakCustomerPlanApiController extends Controller
{
    public function __construct(private readonly SuchakCustomerPlanService $service)
    {
    }

    /** Management list (all plans incl. hidden + presets) plus the effective carousel. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        if ($account === null) {
            return $this->noAccount();
        }

        return response()->json([
            'success' => true,
            'message' => 'Suchak customer plans loaded.',
            'data' => $this->snapshot($account),
        ]);
    }

    /** Create a fully custom reusable plan. */
    public function store(Request $request): JsonResponse
    {
        $account = $this->account($request);
        if ($account === null) {
            return $this->noAccount();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'name_mr' => ['nullable', 'string', 'max:160'],
            'price_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'duration' => ['required', Rule::in(SuchakCustomerPlan::DURATIONS)],
            'services' => ['nullable', 'array'],
            'services.*.name' => ['required_with:services', 'string', 'max:160'],
            'services.*.name_mr' => ['nullable', 'string', 'max:160'],
            'include_basic' => ['nullable', 'boolean'],
            'per_meeting_fee_amount' => ['nullable', 'numeric', 'min:0'],
            // No max/min tied to the offline fee: an online session is priced on its
            // own merits and is allowed to cost more than a visit.
            'per_meeting_online_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'post_marriage_fee_mode' => ['nullable', Rule::in(SuchakCustomerPlan::POST_MARRIAGE_FEE_MODES)],
            'post_marriage_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'original_price_amount' => ['nullable', 'numeric', 'min:0'],
            'private_note' => ['nullable', 'string', 'max:2000'],
            'is_visible' => ['nullable', 'boolean'],
        ]);

        try {
            $plan = $this->service->create($account, $validated);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Custom plan created.',
            'data' => $this->snapshot($account, ['plan_id' => $plan->id]),
        ], 201);
    }

    /**
     * Update a plan. {id} may be a numeric row id, or a preset key
     * ('basic'/'premium') addressing this Suchak's ready-made row — the route the
     * shipped app uses, since it knows the key and not the id.
     *
     * ONE rule set for both. It used to be two, and the narrow one was written
     * out twice: a ready-made plan could only change its price, name, visibility
     * and order, which is precisely why a Suchak could not edit one. The service
     * owns what a field means for which row (see applyPlanFields) — the
     * controller only says what a well-formed value looks like.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $account = $this->account($request);
        if ($account === null) {
            return $this->noAccount();
        }

        $isPresetKey = SuchakDefaultPlans::find($id) !== null;

        if (! $isPresetKey && ! ctype_digit($id)) {
            return $this->notFound();
        }

        $validated = $request->validate($this->planFieldRules());

        try {
            if ($isPresetKey) {
                $plan = $this->service->updatePreset($account, $id, $validated);
            } else {
                $plan = $this->ownedPlan($account, (int) $id);
                if ($plan === null) {
                    return $this->notFound();
                }

                $plan = $this->service->update($plan, $validated);
            }
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plan updated.',
            'data' => $this->snapshot($account, ['plan_id' => $plan->id]),
        ]);
    }

    /** Delete a custom plan. Presets cannot be deleted (only hidden). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $account = $this->account($request);
        if ($account === null) {
            return $this->noAccount();
        }

        if (SuchakDefaultPlans::find($id) !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Preset plans cannot be deleted. Hide them instead.',
            ], 422);
        }

        if (! ctype_digit($id)) {
            return $this->notFound();
        }

        $plan = $this->ownedPlan($account, (int) $id);
        if ($plan === null) {
            return $this->notFound();
        }

        try {
            $this->service->delete($plan);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plan deleted.',
            'data' => $this->snapshot($account),
        ]);
    }

    /** Persist a new order for the given row ids. */
    public function reorder(Request $request): JsonResponse
    {
        $account = $this->account($request);
        if ($account === null) {
            return $this->noAccount();
        }

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        try {
            $this->service->reorder($account, $validated['order']);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Plans reordered.',
            'data' => $this->snapshot($account),
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * What a well-formed plan field looks like on an update — the same answer for
     * a ready-made plan and a custom one.
     *
     * Everything is `sometimes`, because a partial update is the norm here: the
     * management screen sends only `is_visible` for a toggle, and the editor
     * sends the whole form. Whether a NULL is allowed is a per-row question
     * (a ready-made row can fall back to its seed content, a custom row cannot),
     * so the service answers it and returns a 422 with a sentence a Suchak can
     * read, instead of this list guessing.
     *
     * @return array<string, array<int, mixed>>
     */
    private function planFieldRules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'name_mr' => ['sometimes', 'nullable', 'string', 'max:160'],
            'price_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'duration' => ['sometimes', 'nullable', Rule::in(SuchakCustomerPlan::DURATIONS)],
            'services' => ['sometimes', 'array'],
            'services.*.name' => ['required_with:services', 'string', 'max:160'],
            'services.*.name_mr' => ['nullable', 'string', 'max:160'],
            'include_basic' => ['sometimes', 'boolean'],
            'per_meeting_fee_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // No relation to the offline fee, in either direction.
            'per_meeting_online_fee_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'post_marriage_fee_mode' => ['sometimes', 'nullable', Rule::in(SuchakCustomerPlan::POST_MARRIAGE_FEE_MODES)],
            'post_marriage_fee_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'original_price_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'private_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_visible' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function snapshot(SuchakAccount $account, array $extra = []): array
    {
        return array_merge([
            'plans' => $this->service->resolveForManagement($account),
            'carousel' => $this->service->resolveCarousel($account),
        ], $extra);
    }

    private function ownedPlan(SuchakAccount $account, int $id): ?SuchakCustomerPlan
    {
        return SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->whereKey($id)
            ->first();
    }

    private function account(Request $request): ?SuchakAccount
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        return $user->suchakAccount;
    }

    private function noAccount(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Plan not found for this Suchak account.'], 404);
    }

    private function fail(InvalidArgumentException $exception): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
    }
}
