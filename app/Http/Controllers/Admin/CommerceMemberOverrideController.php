<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Services\EntitlementService;
use App\Services\UserEntitlementService;
use App\Support\PlanFeatureKeys;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommerceMemberOverrideController extends Controller
{
    public function index()
    {
        return view('admin.commerce.overrides.index');
    }

    public function lookup(Request $request)
    {
        $request->validate([
            'email' => ['required_without:user_id', 'nullable', 'email'],
            'user_id' => ['required_without:email', 'nullable', 'integer', 'min:1'],
        ]);

        $user = null;
        if ($request->filled('email')) {
            $user = User::query()->where('email', $request->input('email'))->first();
        } elseif ($request->filled('user_id')) {
            $user = User::query()->find((int) $request->input('user_id'));
        }

        if (! $user) {
            return redirect()
                ->route('admin.commerce.overrides.index')
                ->with('error', __('admin_commerce.override_user_not_found'));
        }

        return redirect()->route('admin.commerce.overrides.show', $user);
    }

    public function show(User $user)
    {
        $subscriptions = Subscription::query()
            ->where('user_id', $user->id)
            ->with(['plan', 'planPrice'])
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get();

        $entitlements = UserEntitlement::query()
            ->where('user_id', $user->id)
            ->orderBy('entitlement_key')
            ->get();

        $grantableKeys = array_values(array_unique(array_merge(
            [UserEntitlementService::ENTITLEMENT_CHAT_IMAGE_MESSAGES],
            PlanFeatureKeys::all()
        )));
        sort($grantableKeys);

        return view('admin.commerce.overrides.show', compact(
            'user',
            'subscriptions',
            'entitlements',
            'grantableKeys'
        ));
    }

    public function extendSubscription(Request $request, User $user, EntitlementService $entitlements)
    {
        $data = $request->validate([
            'subscription_id' => ['required', 'integer', Rule::exists('subscriptions', 'id')->where(fn ($q) => $q->where('user_id', $user->id))],
            'extend_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $sub = Subscription::query()
            ->where('id', (int) $data['subscription_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        $days = (int) $data['extend_days'];
        if ($sub->ends_at === null) {
            $sub->ends_at = now()->addDays($days);
        } else {
            $base = $sub->ends_at->isPast() ? now() : $sub->ends_at;
            $sub->ends_at = $base->copy()->addDays($days);
        }
        if ($sub->status !== Subscription::STATUS_ACTIVE) {
            $sub->status = Subscription::STATUS_ACTIVE;
        }
        $sub->save();

        $entitlements->resyncFromActiveSubscription((int) $user->id);

        return redirect()
            ->route('admin.commerce.overrides.show', $user)
            ->with('success', __('admin_commerce.override_extended', ['days' => $days]));
    }

    public function grantEntitlement(Request $request, User $user)
    {
        $keys = array_values(array_unique(array_merge(
            [UserEntitlementService::ENTITLEMENT_CHAT_IMAGE_MESSAGES],
            PlanFeatureKeys::all()
        )));

        $data = $request->validate([
            'entitlement_key' => ['required', 'string', 'max:120', Rule::in($keys)],
            'valid_until' => ['nullable', 'date'],
        ]);

        UserEntitlement::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'entitlement_key' => $data['entitlement_key'],
            ],
            [
                'valid_until' => $data['valid_until'] ?? null,
                'revoked_at' => null,
            ]
        );

        return redirect()
            ->route('admin.commerce.overrides.show', $user)
            ->with('success', __('admin_commerce.override_entitlement_granted'));
    }

    public function revokeEntitlement(Request $request, User $user)
    {
        $data = $request->validate([
            'entitlement_key' => ['required', 'string', 'max:120'],
        ]);

        UserEntitlement::query()
            ->where('user_id', $user->id)
            ->where('entitlement_key', $data['entitlement_key'])
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return redirect()
            ->route('admin.commerce.overrides.show', $user)
            ->with('success', __('admin_commerce.override_entitlement_revoked'));
    }
}
