<?php

namespace App\Observers;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class SubscriptionObserver
{
    public function created(Subscription $subscription)
    {
        // 🔥 Get plan features
        $features = DB::table('plan_features')
            ->where('plan_id', $subscription->plan_id)
            ->get();

        foreach ($features as $f) {
            DB::table('user_entitlements')->updateOrInsert(
                [
                    'user_id' => $subscription->user_id,
                    'entitlement_key' => $f->key, // ✅ correct column
                ],
                [
                    'valid_until' => $subscription->ends_at, // ✅ expiry sync
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
