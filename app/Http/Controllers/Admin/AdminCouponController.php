<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\PlanTerm;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCouponController extends Controller
{
    public function index()
    {
        $coupons = Coupon::query()->orderByDesc('id')->paginate(25);

        return view('admin.commerce.coupons.index', compact('coupons'));
    }

    public function create()
    {
        $plans = Plan::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug']);
        $durationTypes = PlanTerm::billingKeys();

        return view('admin.commerce.coupons.form', [
            'coupon' => new Coupon([
                'type' => Coupon::TYPE_PERCENT,
                'is_active' => true,
                'redemptions_count' => 0,
            ]),
            'plans' => $plans,
            'durationTypes' => $durationTypes,
            'isEdit' => false,
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        $data = $this->validatedCoupon($request);
        Coupon::query()->create($data);

        return redirect()
            ->route('admin.commerce.coupons.index')
            ->with('success', __('admin_commerce.coupon_saved'));
    }

    public function edit(Coupon $coupon)
    {
        $plans = Plan::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug']);
        $durationTypes = PlanTerm::billingKeys();

        return view('admin.commerce.coupons.form', [
            'coupon' => $coupon,
            'plans' => $plans,
            'durationTypes' => $durationTypes,
            'isEdit' => true,
        ]);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        $data = $this->validatedCoupon($request, $coupon->id);
        $coupon->update($data);

        return redirect()
            ->route('admin.commerce.coupons.index')
            ->with('success', __('admin_commerce.coupon_saved'));
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return redirect()
            ->route('admin.commerce.coupons.index')
            ->with('success', __('admin_commerce.coupon_deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCoupon(Request $request, ?int $ignoreCouponId = null): array
    {
        $codeRule = Rule::unique('coupons', 'code');
        if ($ignoreCouponId !== null) {
            $codeRule = $codeRule->ignore($ignoreCouponId);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', $codeRule],
            'type' => ['required', 'string', Rule::in([
                Coupon::TYPE_PERCENT,
                Coupon::TYPE_FIXED,
                Coupon::TYPE_FLAT,
                Coupon::TYPE_DAYS,
                Coupon::TYPE_FEATURE,
            ])],
            'value' => ['required', 'numeric', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'min_purchase_amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:255'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['integer', 'exists:plans,id'],
            'duration_types' => ['nullable', 'array'],
            'duration_types.*' => ['string', Rule::in(PlanTerm::billingKeys())],
            'feature_key' => ['required_if:type,'.Coupon::TYPE_FEATURE, 'nullable', 'string', 'max:64'],
            'grant_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $value = (float) $validated['value'];
        if ($validated['type'] === Coupon::TYPE_PERCENT) {
            $value = min(100, max(0, $value));
        }

        if ($validated['type'] === Coupon::TYPE_DAYS) {
            $value = (float) max(0, (int) round($value));
        }

        $featurePayload = null;
        if ($validated['type'] === Coupon::TYPE_FEATURE) {
            $featurePayload = [
                'feature_key' => strtolower(trim((string) ($validated['feature_key'] ?? ''))),
                'grant_days' => max(1, (int) ($validated['grant_days'] ?? 30)),
            ];
        }

        $persistType = $validated['type'] === Coupon::TYPE_FLAT ? Coupon::TYPE_FIXED : $validated['type'];

        return [
            'code' => strtoupper(trim($validated['code'])),
            'type' => $persistType,
            'value' => $value,
            'max_redemptions' => $validated['max_redemptions'] ?? null,
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'min_purchase_amount' => $validated['min_purchase_amount'] ?? null,
            'description' => $validated['description'] ?? null,
            'applicable_plan_ids' => ! empty($validated['plan_ids']) ? array_values(array_map('intval', $validated['plan_ids'])) : null,
            'applicable_duration_types' => ! empty($validated['duration_types']) ? array_values($validated['duration_types']) : null,
            'feature_payload' => $persistType === Coupon::TYPE_FEATURE ? $featurePayload : null,
        ];
    }
}
