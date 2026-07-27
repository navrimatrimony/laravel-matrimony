<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One FCM registration token = one app install on one physical device.
 *
 * Writes go through {@see \App\Services\Push\DeviceTokenService}; nothing else
 * should create or re-point rows, because the "same device, new owner" rule
 * lives there.
 *
 * @property int $id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property string $app
 * @property string $token
 * @property string $platform
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class DeviceToken extends Model
{
    /** Member app — com.navrimilenavryala.member. */
    public const APP_MEMBER = 'member';

    /** Suchak app — com.navrimilenavryala.suchak. */
    public const APP_SUCHAK = 'suchak';

    public const APPS = [self::APP_MEMBER, self::APP_SUCHAK];

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_WEB = 'web';

    public const PLATFORMS = [self::PLATFORM_ANDROID, self::PLATFORM_IOS, self::PLATFORM_WEB];

    protected $fillable = [
        'tokenable_type',
        'tokenable_id',
        'app',
        'token',
        'platform',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * App\Models\User for a member device, App\Models\SuchakAccount for a Suchak device.
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
