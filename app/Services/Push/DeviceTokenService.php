<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use Illuminate\Database\Eloquent\Model;

/**
 * The ONLY writer of `device_tokens`.
 *
 * It exists mainly to own one rule that is easy to get wrong: an FCM registration
 * token identifies a DEVICE, not an account. The same phone can be handed to a
 * different member, or a member can also be a Suchak, so registering a token that
 * already exists must MOVE it to the new owner rather than insert a second row.
 * Two rows would mean the previous owner keeps receiving pushes on a phone they
 * no longer control — a privacy leak, not just a duplicate.
 */
class DeviceTokenService
{
    /**
     * Register (or re-point) a device token.
     *
     * Idempotent: calling this twice with the same token and owner touches
     * `last_seen_at` and nothing else.
     *
     * @param  Model  $owner  User (member app) or SuchakAccount (Suchak app)
     */
    public function register(Model $owner, string $token, string $app, string $platform = DeviceToken::PLATFORM_ANDROID): DeviceToken
    {
        $token = trim($token);

        return DeviceToken::query()->updateOrCreate(
            ['token' => $token],
            [
                'tokenable_type' => $owner->getMorphClass(),
                'tokenable_id' => $owner->getKey(),
                'app' => in_array($app, DeviceToken::APPS, true) ? $app : DeviceToken::APP_MEMBER,
                'platform' => in_array($platform, DeviceToken::PLATFORMS, true) ? $platform : DeviceToken::PLATFORM_ANDROID,
                'last_seen_at' => now(),
            ],
        );
    }

    /**
     * Logout path. Scoped to the owner on purpose: an authenticated caller must
     * not be able to unregister a device that is not theirs.
     *
     * @return bool whether a row was actually removed
     */
    public function forget(Model $owner, string $token): bool
    {
        return DeviceToken::query()
            ->where('token', trim($token))
            ->where('tokenable_type', $owner->getMorphClass())
            ->where('tokenable_id', $owner->getKey())
            ->delete() > 0;
    }

    /**
     * Unscoped delete, used only when FCM tells us a token is dead
     * (UNREGISTERED / INVALID_ARGUMENT). Never call this from a request path.
     */
    public function forgetDeadToken(string $token): int
    {
        return DeviceToken::query()->where('token', trim($token))->delete();
    }

    /**
     * @return list<string>
     */
    public function tokensFor(Model $owner, ?string $app = null): array
    {
        $query = DeviceToken::query()
            ->where('tokenable_type', $owner->getMorphClass())
            ->where('tokenable_id', $owner->getKey());

        if ($app !== null) {
            $query->where('app', $app);
        }

        return $query->orderByDesc('last_seen_at')->pluck('token')->all();
    }
}
