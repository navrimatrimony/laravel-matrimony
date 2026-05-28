<?php

namespace App\Console\Commands;

use App\Services\NotificationPlatformSettingsService;
use App\Services\NotificationService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Mark subscriptions as expired after ends_at + grace period';

    public function handle(SubscriptionService $subscriptionService, NotificationService $notifications): int
    {
        $windows = app(NotificationPlatformSettingsService::class)->planExpiryNotifyDaysBeforeList();

        $totalSent = 0;
        foreach ($windows as $days) {
            $sent = $notifications->notifySubscriptionsExpiringSoon($days);
            $totalSent += $sent;
            $this->info("Plan-expiry heads-up ({$days} day window): {$sent} subscription(s).");
        }

        if ($totalSent > 0) {
            $this->info("Total plan-expiry heads-up sent this run: {$totalSent}.");
        }

        $n = $subscriptionService->expireSubscriptions();
        $this->info("Expired {$n} subscription(s).");

        return self::SUCCESS;
    }
}
