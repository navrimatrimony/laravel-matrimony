<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\MobilePlanApiController;
use App\Models\Plan;
use App\Models\User;
use App\Services\RevenueOrchestratorService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class MobilePlanCheckoutBridgeController extends Controller
{
    public function __invoke(Request $request, RevenueOrchestratorService $revenue): Response
    {
        if (! $request->hasValidSignature(false)) {
            return $this->blockedPage(
                'Checkout link expired',
                'This mobile checkout link is invalid or expired. Please return to the app and choose the plan again.',
                403,
            );
        }

        $nonce = trim((string) $request->query('nonce', ''));
        if ($nonce === '' || ! preg_match('/^[A-Za-z0-9]{48}$/', $nonce)) {
            return $this->blockedPage(
                'Checkout link invalid',
                'This mobile checkout link is invalid. Please return to the app and choose the plan again.',
                403,
            );
        }

        $payload = Cache::pull(MobilePlanApiController::CHECKOUT_BRIDGE_CACHE_PREFIX.$nonce);
        if (! is_array($payload)) {
            return $this->blockedPage(
                'Checkout link expired',
                'This mobile checkout link has already been used or has expired. Please return to the app and choose the plan again.',
                403,
            );
        }

        $user = User::query()->find((int) ($payload['user_id'] ?? 0));
        $plan = Plan::query()->with('terms')->find((int) ($payload['plan_id'] ?? 0));
        if (! $user instanceof User || ! $plan instanceof Plan) {
            return $this->blockedPage(
                'Checkout unavailable',
                'The selected checkout could not be found. Please return to the app and choose the plan again.',
                422,
            );
        }

        if (! $this->isBuyablePlanForUser($user, $plan)) {
            return $this->blockedPage(
                'Plan unavailable',
                'This plan is not available for checkout.',
                422,
            );
        }

        $planTermId = isset($payload['plan_term_id']) ? (int) $payload['plan_term_id'] : null;
        if ($planTermId !== null
            && ! $plan->terms->contains(fn ($term): bool => (int) $term->id === $planTermId && (bool) $term->is_visible)
        ) {
            return $this->blockedPage(
                'Billing period unavailable',
                'The selected billing period is not available. Please return to the app and choose the plan again.',
                422,
            );
        }

        if ($this->payuConfigMissing()) {
            return $this->blockedPage(
                'Payment gateway unavailable',
                $this->payuConfigMissingMessage(),
                422,
            );
        }

        try {
            $prepared = $revenue->prepareCheckout($user, $plan, $planTermId, null);
            $resolved = is_array($prepared['resolved'] ?? null) ? $prepared['resolved'] : [];
            $finalAmount = isset($resolved['final_amount']) ? (float) $resolved['final_amount'] : 0.0;
            if ($finalAmount <= 0.0) {
                return $this->blockedPage(
                    'Checkout unavailable',
                    __('subscriptions.subscribe_failed'),
                    422,
                );
            }
        } catch (HttpException $exception) {
            return $this->blockedPage('Checkout blocked', $exception->getMessage(), $this->httpStatus($exception));
        } catch (Throwable $exception) {
            report($exception);

            return $this->blockedPage(
                'Checkout unavailable',
                __('subscriptions.subscribe_failed'),
                422,
            );
        }

        Auth::guard('web')->loginUsingId((int) $user->id, false);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $checkoutRequest = Request::create(
            route('plans.subscribe', [], false),
            'GET',
            array_filter([
                'plan' => (string) $plan->slug,
                'plan_term_id' => $planTermId !== null ? (string) $planTermId : null,
            ], fn ($value): bool => $value !== null && $value !== ''),
        );
        $checkoutRequest->setUserResolver(fn (): User => $user);
        if ($request->hasSession()) {
            $checkoutRequest->setLaravelSession($request->session());
        }

        Log::info('mobile_plan_checkout_bridge_rendered', [
            'user_id' => (int) $user->id,
            'plan_id' => (int) $plan->id,
            'plan_term_id' => $planTermId,
        ]);

        return app(SubscriptionController::class)->subscribe(
            $checkoutRequest,
            app(SubscriptionService::class),
            $revenue,
        );
    }

    private function isBuyablePlanForUser(User $user, Plan $plan): bool
    {
        return (bool) $plan->is_active
            && (bool) $plan->is_visible
            && ! Plan::isFreeCatalogSlug((string) $plan->slug)
            && Plan::profileGenderAllowsPlan($user, $plan);
    }

    private function payuConfigMissing(): bool
    {
        return trim((string) config('payu.merchant_key', '')) === ''
            || trim((string) config('payu.merchant_salt', '')) === ''
            || trim((string) config('payu.checkout_url', '')) === '';
    }

    private function payuConfigMissingMessage(): string
    {
        return 'Payment gateway is not configured. Please contact support.';
    }

    private function httpStatus(HttpException $exception): int
    {
        $status = $exception->getStatusCode();

        return $status >= 400 && $status < 600 ? $status : 422;
    }

    private function blockedPage(string $title, string $message, int $status): Response
    {
        $html = '<!doctype html>'
            .'<html lang="en">'
            .'<head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($title).'</title>'
            .'<style>'
            .'body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f8f4ef;color:#2f1f1f;}'
            .'.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}'
            .'.box{max-width:520px;width:100%;background:#fff;border:1px solid #eadbd4;border-radius:12px;padding:24px;box-shadow:0 10px 30px rgba(47,31,31,.08);}'
            .'h1{margin:0 0 10px;font-size:22px;line-height:1.2;color:#9f1239;}'
            .'p{margin:0;color:#5f4a45;line-height:1.5;}'
            .'</style>'
            .'</head>'
            .'<body><div class="wrap"><main class="box">'
            .'<h1>'.e($title).'</h1>'
            .'<p>'.e($message).'</p>'
            .'</main></div></body></html>';

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
