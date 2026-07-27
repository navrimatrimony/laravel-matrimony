<?php

namespace App\Support\Suchak;

use App\Models\MatrimonyProfile;
use App\Models\ProfileVisibilitySetting;
use App\Models\SuchakAccount;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE definition of "this profile's contact goes through a Suchak".
 *
 * This predicate used to be copy-pasted in five places (web contact reveal, web
 * contact request, the two mobile contact controllers and the mobile display
 * presenter). They had already drifted on their Schema guards, which is exactly
 * how a routed profile ends up leaking a contact on one surface and not another.
 *
 * A profile is Suchak-routed when a PUBLICLY ROUTABLE representation exists
 * (publiclyRoutable() already means: active + valid consent + verified, publicly
 * active Suchak account) AND either
 *   - that representation is one the Suchak created themselves, or
 *   - the candidate set contact_routing_mode = suchak_only.
 *
 * Nothing here ever returns the candidate's own number. The only number this
 * class can produce is the SUCHAK's own business number.
 */
final class SuchakContactRouting
{
    private function __construct()
    {
    }

    public static function isRouted(MatrimonyProfile $profile): bool
    {
        if (! Schema::hasTable('suchak_profile_representations')) {
            return false;
        }

        $routableQuery = SuchakProfileRepresentation::query()
            ->publiclyRoutable()
            ->where('matrimony_profile_id', $profile->id);

        if ((clone $routableQuery)
            ->whereIn('representation_mode', SuchakProfileRepresentation::SUCHAK_CREATED_MODES)
            ->exists()) {
            return true;
        }

        if (! (clone $routableQuery)->exists()
            || ! Schema::hasTable('profile_visibility_settings')
            || ! Schema::hasColumn('profile_visibility_settings', 'contact_routing_mode')) {
            return false;
        }

        $mode = DB::table('profile_visibility_settings')
            ->where('profile_id', $profile->id)
            ->value('contact_routing_mode');

        return ProfileVisibilitySetting::normalizeContactRoutingMode(is_string($mode) ? $mode : null)
            === ProfileVisibilitySetting::CONTACT_ROUTING_SUCHAK_ONLY;
    }

    /**
     * Every routable representation for this profile, eager-loaded exactly the
     * way the web profile page loads them so both surfaces read the same rows.
     *
     * @return Collection<int, SuchakProfileRepresentation>
     */
    public static function routableRepresentations(MatrimonyProfile $profile): Collection
    {
        if (! Schema::hasTable('suchak_profile_representations')) {
            /** @var Collection<int, SuchakProfileRepresentation> $empty */
            $empty = SuchakProfileRepresentation::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        return SuchakProfileRepresentation::query()
            ->with([
                'suchakAccount.contactNumbers' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderByDesc('is_whatsapp')
                    ->orderBy('id'),
            ])
            ->publiclyRoutable()
            ->where('matrimony_profile_id', $profile->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * The representation a request should be addressed to. When the caller names
     * one it must belong to this profile and still be routable; otherwise the
     * lowest-id routable representation is used (same order as the web page).
     */
    public static function routableRepresentationFor(
        MatrimonyProfile $profile,
        ?int $representationId = null,
    ): ?SuchakProfileRepresentation {
        $representations = self::routableRepresentations($profile);

        if ($representationId !== null) {
            return $representations->first(
                fn (SuchakProfileRepresentation $representation): bool => (int) $representation->id === $representationId,
            );
        }

        return $representations->first();
    }

    /**
     * Display name shown to a member. Mirrors the web profile page and the chat
     * reply prefix so the member never sees two different names for one Suchak.
     */
    public static function displayName(?SuchakAccount $account): string
    {
        $name = trim((string) (
            $account?->office_name_mr
            ?: $account?->office_name
            ?: $account?->suchak_name_mr
            ?: $account?->suchak_name
            ?: ''
        ));

        return $name !== '' ? $name : __('profile.suchak_default_name');
    }

    /**
     * The SUCHAK's own contact number — never the candidate's. Same resolution
     * order as the web reveal path: active account number, then WhatsApp, then
     * the account's login mobile.
     */
    public static function accountPhone(?SuchakAccount $account): string
    {
        $contactNumber = $account?->contactNumbers
            ?->first(fn ($number): bool => (bool) ($number->is_active ?? false)
                && trim((string) ($number->phone_number ?? '')) !== '');

        if ($contactNumber) {
            return trim((string) $contactNumber->phone_number);
        }

        $whatsapp = trim((string) ($account?->whatsapp_number ?? ''));
        if ($whatsapp !== '') {
            return $whatsapp;
        }

        return trim((string) ($account?->mobile_number ?? ''));
    }

    /**
     * 4 visible digits + X padding, identical to the web card, so a masked
     * number never differs between surfaces. Latin digits only.
     */
    public static function maskedAccountPhone(?SuchakAccount $account): ?string
    {
        $digits = preg_replace('/\D/', '', self::accountPhone($account)) ?? '';

        if ($digits === '') {
            return null;
        }

        return strlen($digits) >= 4
            ? substr($digits, 0, 4).'XXXXXX'
            : 'XXXXXX';
    }
}
