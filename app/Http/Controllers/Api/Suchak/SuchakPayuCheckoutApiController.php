<?php

namespace App\Http\Controllers\Api\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakPlan;
use App\Models\User;
use App\Modules\Suchak\Services\SuchakPlanPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Track B PayU checkout handoff — thin JSON over SuchakPlanPaymentService::startCheckout.
 * Returns action URL + form fields for mobile WebView auto-submit (same as web redirect page).
 */
class SuchakPayuCheckoutApiController extends Controller
{
    public function start(
        Request $request,
        SuchakPlan $plan,
        SuchakPlanPaymentService $payments,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User || $user->suchakAccount === null) {
            return response()->json(['success' => false, 'message' => 'Suchak account is required.'], 403);
        }

        try {
            $checkout = $payments->startCheckout(
                $user->suchakAccount,
                $user,
                $plan,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (HttpException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->getStatusCode());
        }

        return response()->json([
            'success' => true,
            'message' => 'PayU checkout started.',
            'data' => [
                'track' => 'B',
                'plan_id' => $plan->id,
                'action' => $checkout['action'],
                'fields' => $checkout['fields'],
                'payment_id' => $checkout['payment']->id ?? null,
            ],
        ]);
    }
}
