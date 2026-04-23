<?php

use App\Models\PlanQuotaPolicy;
use App\Support\PlanFeatureKeys;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PlanQuotaPolicy::query()
            ->where('feature_key', PlanFeatureKeys::CHAT_SEND_LIMIT)
            ->each(function (PlanQuotaPolicy $p): void {
                $m = $p->policy_meta;
                if (! is_array($m)) {
                    $m = [];
                }
                if (! array_key_exists('chat_initiate_new_chats_only', $m)) {
                    $m['chat_initiate_new_chats_only'] = false;
                    $p->policy_meta = $m;
                    $p->save();
                }
            });

        PlanQuotaPolicy::query()
            ->where('feature_key', PlanFeatureKeys::INTEREST_VIEW_LIMIT)
            ->each(function (PlanQuotaPolicy $p): void {
                $m = $p->policy_meta;
                if (! is_array($m)) {
                    $m = [];
                }
                if (! array_key_exists('interest_view_reset_period', $m)) {
                    $m['interest_view_reset_period'] = 'monthly';
                    $p->policy_meta = $m;
                    $p->save();
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive: do not strip keys added for SSOT completeness.
    }
};
