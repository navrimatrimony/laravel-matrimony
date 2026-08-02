<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakMarketEconomicsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The market economics view (blueprint phase 5, §9 "another customer's fees — market economics").
 *
 * `GET /api/v1/suchak/marketplace/economics`
 *
 * What the marketplace looks like to a Suchak deciding whether to publish, and at what share: how
 * much is open, how much of what was published got answered and how fast, what shares are typically
 * declared, and how often publishing ends in a marriage.
 *
 * NO PARAMETERS, and that is a decision rather than an omission. Every knob a caller could turn —
 * a date range, a district, a caste, a publisher — is a way of narrowing an aggregate onto a
 * smaller population, and the whole protection here is that the population is large enough that no
 * individual's terms can be read out of it. A filter would let a reader shrink the set until the
 * threshold's denominator was one Suchak he had already picked out of the browse list, which is
 * the same oracle the proposal inbox refuses one screen over. The window is the service's
 * (`WINDOW_DAYS`) and is reported in the payload so nobody has to guess what "typical" covers.
 *
 * Every rupee and every percentage in the response is preformatted server-side (Latin digits,
 * Indian grouping) through `MoneyFormat` and `PercentDisplay`. No client re-derives money.
 *
 * 200: `{ success, data: { as_of, window, minimum_population, open_now, supply, response,
 *        declared_share, outcomes } }`. Every block except `open_now` carries `observations`,
 * `publishers`, `is_withheld` and `withheld_reason`, and its figures are null when withheld.
 * 422 when the caller does not hold the marketplace badge (D18 / §9 — the matrix grants this to
 * verified Suchaks and to nobody else).
 */
class SuchakMarketEconomicsApiController extends Controller
{
    public function __invoke(
        Request $request,
        SuchakMarketEconomicsService $economicsService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json([
                'success' => false,
                'message' => __('suchak.api.errors.suchak_account_required'),
            ], 403);
        }

        try {
            $market = $economicsService->marketFor($user->suchakAccount);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $market,
        ]);
    }
}
