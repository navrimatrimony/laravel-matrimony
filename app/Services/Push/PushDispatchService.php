<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Services\NotificationPlatformSettingsService;
use App\Services\UserNotificationPreferencesService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE decision point. "A notification row was written for someone → should that
 * become a push, and if so, what does it say and where does it go?"
 *
 * There is exactly one of these, reached from exactly one place
 * ({@see \App\Listeners\SendPushForDatabaseNotification}, bound to Laravel's
 * NotificationSent event). No business flow calls push directly, and none should:
 * when a future feature writes a notification row it gets push behaviour for free,
 * and turning a type off is an admin toggle rather than a deploy.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * DELIVERY IS SYNCHRONOUS, AND THAT IS DELIBERATE
 * ─────────────────────────────────────────────────────────────────────────────
 * Production runs QUEUE_CONNECTION=database with workers that serve only the
 * default and `bulk-intake` queues. On 2026-07-27 the `notifications` queue held
 * 82 jobs that had been due since 2026-06-17 — nothing serves it. A push
 * dispatched there would never be delivered and nothing would report an error.
 * So the FCM call happens inline, guarded by a short timeout, and every failure
 * is logged rather than swallowed by a queue nobody drains.
 *
 * Moving to a queue later is a one-line change: wrap {@see self::deliver()} in a
 * job. Do NOT wrap it in a job pointed at `notifications` — that queue needs a
 * worker provisioned first.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class PushDispatchService
{
    public function __construct(
        private readonly PushTypeRegistry $registry,
        private readonly NotificationPlatformSettingsService $platform,
        private readonly UserNotificationPreferencesService $preferences,
        private readonly DeviceTokenService $deviceTokens,
        private readonly FirebasePushService $firebase,
    ) {}

    /**
     * Entry point from the NotificationSent listener.
     *
     * Never throws: it is called from inside whatever request or job created the
     * notification, and a push problem must not roll that back.
     *
     * @param  array<string, mixed>  $data  the notification's stored `data` array
     * @return array{sent: bool, reason: string}
     */
    public function dispatchForDatabaseNotification(object $notifiable, string $notificationType, array $data = []): array
    {
        try {
            return $this->decideAndSend($notifiable, $notificationType, $data);
        } catch (Throwable $e) {
            Log::error('push.dispatch_failed', [
                'notification_type' => $notificationType,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'reason' => 'exception'];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sent: bool, reason: string}
     */
    private function decideAndSend(object $notifiable, string $notificationType, array $data): array
    {
        // 1. Channel master switch (.env kill switch, overridable by admin).
        if (! $this->platform->pushEnabled()) {
            return ['sent' => false, 'reason' => 'channel_disabled'];
        }

        // 2. Is this a type we have wording and a deep-link target for?
        //    An unregistered type is skipped, not an error — see PushTypeRegistry.
        $pushKey = $this->registry->keyForNotificationType($notificationType);
        if ($pushKey === null) {
            Log::info('push.unregistered_type', ['notification_type' => $notificationType]);

            return ['sent' => false, 'reason' => 'unregistered_type'];
        }

        // 3. Admin switch for this specific type. Admin OFF beats any user choice.
        if (! $this->platform->pushTypeEnabled($pushKey)) {
            return ['sent' => false, 'reason' => 'type_disabled_by_admin'];
        }

        if (! $notifiable instanceof Model) {
            return ['sent' => false, 'reason' => 'unsupported_notifiable'];
        }

        // 4. The person's own choice.
        if (! $this->preferences->pushTypeEnabled($notifiable, $pushKey)) {
            return ['sent' => false, 'reason' => 'type_disabled_by_user'];
        }

        // 5. Quiet hours.
        if ($this->inQuietHours($notifiable)) {
            Log::info('push.quiet_hours_suppressed', [
                'push_key' => $pushKey,
                'window' => $this->quietHoursLabel(),
            ]);

            return ['sent' => false, 'reason' => 'quiet_hours'];
        }

        $tokens = $this->tokensFor($notifiable, $pushKey);
        if ($tokens === []) {
            return ['sent' => false, 'reason' => 'no_devices'];
        }

        $result = $this->deliver(
            $tokens,
            $this->title($pushKey, $data),
            $this->body($pushKey, $data),
            $this->payload($pushKey, $data),
        );

        return ['sent' => ($result['sent'] ?? 0) > 0, 'reason' => ($result['sent'] ?? 0) > 0 ? 'sent' : 'delivery_failed'];
    }

    /**
     * The single seam a future queue job would wrap. Everything above this line
     * is a decision; everything below it is transport.
     *
     * @param  list<string>  $tokens
     * @param  array<string, scalar|null>  $payload
     * @return array<string, mixed>
     */
    public function deliver(array $tokens, string $title, string $body, array $payload): array
    {
        return $this->firebase->sendToTokens($tokens, $title, $body, $payload);
    }

    /**
     * Devices belonging to this notifiable, restricted to apps that can render
     * this type.
     *
     * A member's tokens hang off the User row; a Suchak's off the SuchakAccount
     * row (that is how they were registered), so the notifiable is used as-is.
     *
     * @return list<string>
     */
    private function tokensFor(Model $notifiable, string $pushKey): array
    {
        $apps = (array) ($this->registry->get($pushKey)['apps'] ?? []);
        $tokens = [];

        foreach ($this->ownersFor($notifiable) as $owner) {
            foreach ($apps as $app) {
                $tokens = array_merge($tokens, $this->deviceTokens->tokensFor($owner, $app));
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Every device-owning identity behind one notifiable.
     *
     * Notifications are addressed to a `User`, but a User who is also a Suchak has
     * devices registered under their `SuchakAccount`. Without this, a Suchak-app
     * install would never receive anything, because nothing is ever notified
     * "as a SuchakAccount".
     *
     * @return list<Model>
     */
    private function ownersFor(Model $notifiable): array
    {
        $owners = [$notifiable];

        if ($notifiable instanceof User) {
            $suchakAccount = $notifiable->suchakAccount;
            if ($suchakAccount instanceof SuchakAccount) {
                $owners[] = $suchakAccount;
            }
        }

        if ($notifiable instanceof SuchakAccount && $notifiable->user instanceof User) {
            $owners[] = $notifiable->user;
        }

        return $owners;
    }

    /**
     * Quiet hours: SUPPRESS, do not hold.
     *
     * The brief allowed either. Holding was rejected because both mechanisms for
     * it are unreliable here: a delayed queue job would land on a queue with no
     * worker (see the class docblock), and a hold table would need a scheduler
     * tick this deployment cannot currently guarantee. A "held" push that is
     * never released is a silently dropped push — the exact failure mode the
     * brief warned about.
     *
     * Nothing is actually lost: the database notification row is always written
     * regardless, so the member still sees the item in the app's notification
     * list. Only the night-time buzz is skipped.
     */
    private function inQuietHours(?Model $notifiable): bool
    {
        if (! $this->platform->pushQuietHoursEnabled()) {
            return false;
        }

        if (! $this->preferences->quietHoursEnabled($notifiable)) {
            return false;
        }

        $start = $this->platform->pushQuietHoursStart();
        $end = $this->platform->pushQuietHoursEnd();

        if ($start === $end) {
            // A zero-length window means "no quiet hours", never "always quiet".
            return false;
        }

        $hour = (int) CarbonImmutable::now(config('app.timezone'))->format('G');

        // A window that crosses midnight (22 → 8) is the normal case.
        return $start < $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);
    }

    /**
     * "22:00 – 08:00", always in Latin digits (frozen workspace rule).
     */
    public function quietHoursLabel(): string
    {
        return sprintf(
            '%02d:%02d – %02d:%02d',
            $this->platform->pushQuietHoursStart(), 0,
            $this->platform->pushQuietHoursEnd(), 0,
        );
    }

    /**
     * A notification may override the generic copy by putting `push_title` /
     * `push_body` in its own `toDatabase()` array. Otherwise the reviewed strings
     * from lang/{en,mr}/push.php are used.
     *
     * @param  array<string, mixed>  $data
     */
    private function title(string $pushKey, array $data): string
    {
        $override = trim((string) ($data['push_title'] ?? ''));

        return $override !== '' ? $override : (string) __('push.types.'.$pushKey.'.title');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function body(string $pushKey, array $data): string
    {
        $override = trim((string) ($data['push_body'] ?? ''));
        if ($override !== '') {
            return $override;
        }

        // Scalars only — the translator replaces `:key` placeholders from these.
        $replace = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $replace[(string) $key] = (string) $value;
            }
        }

        return (string) __('push.types.'.$pushKey.'.body', $replace);
    }

    /**
     * The FCM `data` block: what the app needs to open the right screen.
     *
     * Always carries `type` (the push key) and `target` (the deep-link target),
     * plus whichever ids the registry declared for this type. Nothing else — the
     * data block is not a place to mirror the whole notification.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    private function payload(string $pushKey, array $data): array
    {
        $row = $this->registry->get($pushKey) ?? [];

        $payload = [
            'type' => $pushKey,
            'target' => (string) ($row['target'] ?? 'notifications'),
        ];

        foreach ((array) ($row['data_keys'] ?? []) as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value)) {
                $payload[(string) $key] = $value;
            }
        }

        if (isset($data['id']) && is_scalar($data['id'])) {
            $payload['notification_id'] = $data['id'];
        }

        return $payload;
    }

    /**
     * Manual send used by `php artisan push:test` — bypasses the switchboard on
     * purpose, so the product owner can prove the transport works even while
     * every type is still switched off.
     *
     * @return array<string, mixed>
     */
    public function sendRawToToken(string $token, string $title, string $body, array $data = []): array
    {
        return $this->firebase->sendToTokens([$token], $title, $body, $data + [
            'type' => 'test',
            'target' => 'notifications',
        ]);
    }

    /**
     * Devices for one owner, for one app — used by the test command's
     * `--user` / `--suchak` shortcuts.
     *
     * @return list<string>
     */
    public function tokensForOwner(Model $owner, ?string $app = null): array
    {
        return $this->deviceTokens->tokensFor($owner, $app);
    }

    /**
     * Categories a given app should render in its notification-settings screen.
     *
     * ONLY types the admin has enabled platform-wide are returned: showing a
     * switch whose outcome the member cannot affect is worse than hiding it.
     *
     * @return list<array{key: string, group: string, group_label: string, label: string, description: string, push_enabled: bool}>
     */
    public function settingsCategoriesFor(string $app, ?Model $owner): array
    {
        $rows = [];

        foreach ($this->registry->forApp($app) as $key => $row) {
            if (! $this->platform->pushTypeEnabled($key)) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'group' => $row['group'],
                'group_label' => $this->registry->groupLabel($row['group']),
                'label' => $this->registry->label($key),
                'description' => $this->registry->description($key),
                'push_enabled' => $this->preferences->pushTypeEnabled($owner, $key),
            ];
        }

        // Stable, group-ordered output so both apps render the same sections.
        usort($rows, static function (array $a, array $b): int {
            $order = array_flip(PushTypeRegistry::GROUPS);

            return [$order[$a['group']] ?? 99, $a['key']] <=> [$order[$b['group']] ?? 99, $b['key']];
        });

        return $rows;
    }

    /**
     * @return array{enabled: bool, starts_at: string, ends_at: string, label: string, description: string}
     */
    public function quietHoursPayload(?Model $owner): array
    {
        $startsAt = sprintf('%02d:00', $this->platform->pushQuietHoursStart());
        $endsAt = sprintf('%02d:00', $this->platform->pushQuietHoursEnd());

        return [
            // Reflects the member's own switch; the admin window is the schedule.
            'enabled' => $this->platform->pushQuietHoursEnabled() && $this->preferences->quietHoursEnabled($owner),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'label' => (string) __('push.quiet_hours.label'),
            'description' => (string) __('push.quiet_hours.description', ['start' => $startsAt, 'end' => $endsAt]),
        ];
    }

    /**
     * Apps a device may register under, for validation.
     *
     * @return list<string>
     */
    public function knownApps(): array
    {
        return DeviceToken::APPS;
    }
}
