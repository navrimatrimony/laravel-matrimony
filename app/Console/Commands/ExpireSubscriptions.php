<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Mark subscriptions as expired after ends_at + grace period';

    public function handle(SubscriptionService $subscriptionService, NotificationService $notifications): int
    {
        $days = (int) config('monetization.plan_expiry_notify_days_before', 2);
        if ($days > 0) {
            $sent = $notifications->notifySubscriptionsExpiringSoon($days);
            $this->info("Queued plan-expiry heads-up for {$sent} subscription(s) (≈{$days} days).");
        }

        $n = $subscriptionService->expireSubscriptions();
        $this->info("Expired {$n} subscription(s).");

        return self::SUCCESS;
    }
}
