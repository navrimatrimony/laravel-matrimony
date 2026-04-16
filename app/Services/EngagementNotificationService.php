<?php

namespace App\Services;

use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Notifications\InactiveUserReminderNotification;
use App\Notifications\NewMatchesAvailableNotification;
use App\Services\Messaging\MetaWhatsAppCloudService;
use App\Services\Matching\MatchingService;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled engagement: inactive reminders + new-match digest (database + optional email/WhatsApp).
 */
class EngagementNotificationService
{
    public function __construct(
        protected MatchingService $matching,
        protected MetaWhatsAppCloudService $whatsapp,
    ) {}

    public function sendInactiveReminders(): int
    {
        $cfg = config('engagement.inactive_reminder', []);
        if (! ($cfg['enabled'] ?? true)) {
            return 0;
        }

        $afterDays = max(1, (int) ($cfg['after_days'] ?? 3));
        $cooldownDays = max(1, (int) ($cfg['cooldown_days'] ?? 7));
        $threshold = now()->subDays($afterDays);
        $cooldownCut = now()->subDays($cooldownDays);

        $sent = 0;

        User::query()
            ->whereHas('matrimonyProfile', function ($q): void {
                $q->where('lifecycle_state', 'active');
            })
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $threshold)
            ->where(function ($q) use ($cooldownCut): void {
                $q->whereNull('last_inactive_reminder_sent_at')
                    ->orWhere('last_inactive_reminder_sent_at', '<', $cooldownCut);
            })
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$sent, $cfg): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    try {
                        $user->notify(new InactiveUserReminderNotification);
                        $user->forceFill(['last_inactive_reminder_sent_at' => now()])->saveQuietly();
                        if (($cfg['whatsapp']['enabled'] ?? false) && $this->whatsapp->canSendEngagementTemplate()) {
                            $mobile = preg_replace('/\D/', '', (string) ($user->mobile ?? ''));
                            if (strlen($mobile) >= 10) {
                                $line = __('notifications.inactive_reminder_whatsapp_line', [
                                    'app' => config('app.name'),
                                    'url' => config('app.url'),
                                ]);
                                $this->whatsapp->sendEngagementTemplate($mobile, $line);
                            }
                        }
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::warning('engagement_inactive_reminder_failed', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $sent;
    }

    public function sendNewMatchDigests(): int
    {
        $cfg = config('engagement.new_matches_digest', []);
        if (! ($cfg['enabled'] ?? true)) {
            return 0;
        }

        $minScore = max(0, (int) ($cfg['min_score'] ?? 55));
        $tab = MatchingService::normalizeTab((string) ($cfg['tab'] ?? MatchingService::TAB_PERFECT));
        $limit = max(1, (int) ($cfg['candidate_limit'] ?? 12));
        $minMatches = max(1, (int) ($cfg['min_matches'] ?? 1));
        $cooldownDays = max(1, (int) ($cfg['cooldown_days'] ?? 1));
        $cooldownCut = now()->subDays($cooldownDays);

        $sent = 0;

        User::query()
            ->whereHas('matrimonyProfile', function ($q): void {
                $q->where('lifecycle_state', 'active');
            })
            ->where(function ($q) use ($cooldownCut): void {
                $q->whereNull('last_new_matches_digest_sent_at')
                    ->orWhere('last_new_matches_digest_sent_at', '<', $cooldownCut);
            })
            ->with('matrimonyProfile')
            ->orderBy('id')
            ->chunkById(150, function ($users) use (&$sent, $minScore, $tab, $limit, $minMatches): void {
                foreach ($users as $user) {
                    /** @var User $user */
                    $profile = $user->matrimonyProfile;
                    if (! $profile instanceof MatrimonyProfile) {
                        continue;
                    }
                    try {
                        $rows = $this->matching->findMatchesForTab($profile, $tab, $limit, false);
                        $count = 0;
                        $top = 0;
                        foreach ($rows as $row) {
                            $score = (int) ($row['score'] ?? 0);
                            if ($score >= $minScore) {
                                $count++;
                                $top = max($top, $score);
                            }
                        }
                        if ($count < $minMatches) {
                            continue;
                        }
                        $user->notify(new NewMatchesAvailableNotification($count, $top, $tab));
                        $user->forceFill(['last_new_matches_digest_sent_at' => now()])->saveQuietly();
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::warning('engagement_new_matches_digest_failed', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $sent;
    }
}
