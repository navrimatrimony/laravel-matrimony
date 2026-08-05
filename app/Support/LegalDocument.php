<?php

namespace App\Support;

use App\Services\SiteIdentityService;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Resolver for the five public legal documents.
 *
 * Text lives once, in lang/{locale}/legal.php. Company facts live once, in
 * config/legal.php. This class is the only place the two are joined: it pulls
 * the translated document, substitutes every :token with the configured fact,
 * and hands the finished array to the Blade renderer.
 *
 * Facts that SiteIdentityService already owns (support email, public phone,
 * public address) are read from there first — the config values act as the
 * fallback so nothing is duplicated between the two stores.
 */
class LegalDocument
{
    /** Registry order is the order the documents appear in the footer. */
    public static function keys(): array
    {
        return array_keys((array) config('legal.documents', []));
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * The rendered document: title, summary and sections with every token resolved.
     */
    public static function content(string $key): array
    {
        $document = __('legal.'.$key);

        if (! is_array($document)) {
            return ['title' => $key, 'summary' => '', 'sections' => []];
        }

        return self::substitute($document, self::replacements());
    }

    /**
     * Version stamp shown on the page and referenced by the signup consent ledger.
     */
    public static function meta(string $key): array
    {
        return [
            'key' => $key,
            'version' => (string) config('legal.versions.'.$key, config('legal.effective_from')),
            'effective_from' => (string) config('legal.effective_from'),
            'last_updated' => (string) config('legal.last_updated'),
            'url' => self::url($key),
        ];
    }

    public static function url(string $key): ?string
    {
        $name = config('legal.documents.'.$key.'.route');

        return ($name && RouteFacade::has($name)) ? route($name) : null;
    }

    /**
     * Footer / index links: [key => ['url' => ..., 'label' => ...]].
     */
    public static function links(): array
    {
        $links = [];

        foreach (self::keys() as $key) {
            $url = self::url($key);

            if ($url === null) {
                continue;
            }

            $links[$key] = [
                'url' => $url,
                'label' => __('legal.'.$key.'.title'),
            ];
        }

        return $links;
    }

    /**
     * Every :token available inside lang/{locale}/legal.php.
     */
    public static function replacements(): array
    {
        $site = app(SiteIdentityService::class);

        // config/legal.php is authoritative. The DB-backed Site Identity setting is
        // consulted ONLY to fill a placeholder the product owner has not filled in
        // yet, so the two stores can never contradict each other on a published page.
        $resolve = static function (string $configValue, ?string $adminValue): string {
            if (! self::isUnfilled($configValue)) {
                return $configValue;
            }

            $adminValue = trim((string) $adminValue);

            return $adminValue !== '' ? $adminValue : $configValue;
        };

        $supportEmail = $resolve(
            (string) config('legal.contact.support_email'),
            $site->get('support_email')
        );

        $contactMobile = $resolve(
            (string) config('legal.contact.mobile'),
            $site->get('primary_phone')
        );

        // Deliberately NOT resolved from SiteIdentityService::get('address'). That
        // setting is a free-form display address for the marketing footer; this one
        // is the LLP's statutory registered office. They are different facts, and
        // letting a display string stand in for a registered office would publish a
        // fabricated statutory address on five legal pages.
        $registeredAddress = (string) config('legal.entity.registered_address');

        // The officer sits at the registered address unless config names a different one.
        $configuredOfficerAddress = (string) config('legal.grievance.officer_address');
        $officerAddress = $configuredOfficerAddress === (string) config('legal.entity.registered_address')
            ? $registeredAddress
            : $configuredOfficerAddress;

        return [
            // Entity
            ':legal_name' => (string) config('legal.entity.legal_name'),
            ':brand_name' => (string) config('legal.entity.brand_name'),
            ':website' => (string) config('legal.entity.website'),
            ':domain' => (string) config('legal.entity.domain'),
            ':llpin' => (string) config('legal.entity.llpin'),
            ':incorporated_on' => (string) config('legal.entity.incorporated_on'),
            ':registered_address' => $registeredAddress,
            ':jurisdiction_city' => (string) config('legal.entity.jurisdiction_city'),
            ':jurisdiction_state' => (string) config('legal.entity.jurisdiction_state'),

            // Contact
            ':contact_mobile' => $contactMobile,
            ':support_email' => $supportEmail,

            // Grievance officer
            ':officer_name' => (string) config('legal.grievance.officer_name'),
            ':officer_designation' => (string) config('legal.grievance.officer_designation'),
            ':officer_email' => (string) config('legal.grievance.officer_email'),
            ':officer_phone' => (string) config('legal.grievance.officer_phone'),
            ':officer_address' => $officerAddress,
            ':officer_hours' => (string) config('legal.grievance.officer_hours'),
            ':ack_hours' => (string) config('legal.grievance.acknowledgement_hours'),
            ':resolution_days' => (string) config('legal.grievance.resolution_days'),
            ':takedown_hours' => (string) config('legal.grievance.urgent_takedown_hours'),
            ':dpdp_days' => (string) config('legal.grievance.dpdp_response_days'),
            ':escalation_days' => (string) config('legal.grievance.escalation_days'),

            // Versions and dates
            ':terms_version' => (string) config('legal.versions.terms'),
            ':privacy_version' => (string) config('legal.versions.privacy'),
            ':refund_version' => (string) config('legal.versions.refund'),
            ':disclaimer_version' => (string) config('legal.versions.disclaimer'),
            ':grievance_version' => (string) config('legal.versions.grievance'),
            ':effective_from' => (string) config('legal.effective_from'),
            ':last_updated' => (string) config('legal.last_updated'),

            // Refund windows
            ':cooling_off_hours' => (string) config('legal.refund.cooling_off_hours'),
            ':refund_processing_days' => (string) config('legal.refund.processing_days'),
            ':bank_credit_days' => (string) config('legal.refund.bank_credit_days'),
            ':payment_gateway' => (string) config('legal.refund.gateway'),

            // Retention windows
            ':deletion_days' => (string) config('legal.retention.deletion_request_days'),
            ':financial_years' => (string) config('legal.retention.financial_records_years'),
            ':inactive_months' => (string) config('legal.retention.inactive_account_months'),

            // Cross-document links
            ':terms_url' => (string) (self::url('terms') ?? ''),
            ':privacy_url' => (string) (self::url('privacy') ?? ''),
            ':refund_url' => (string) (self::url('refund') ?? ''),
            ':disclaimer_url' => (string) (self::url('disclaimer') ?? ''),
            ':grievance_url' => (string) (self::url('grievance') ?? ''),
        ];
    }

    /**
     * True when a configured value is still an unfilled [[TOKEN]] placeholder.
     */
    public static function isUnfilled(string $value): bool
    {
        return (bool) preg_match('/\[\[[A-Z0-9_]+\]\]/', $value);
    }

    /**
     * Placeholder tokens still left unfilled in config/legal.php, e.g. [[LLPIN]].
     * Used by the admin-facing warning strip so nobody ships a half-filled policy.
     *
     * @return array<int, string>
     */
    public static function unfilledPlaceholders(): array
    {
        $found = [];

        foreach (self::replacements() as $value) {
            if (preg_match_all('/\[\[[A-Z0-9_]+\]\]/', (string) $value, $matches)) {
                foreach ($matches[0] as $token) {
                    $found[$token] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private static function substitute(array $document, array $replacements): array
    {
        array_walk_recursive($document, function (&$value) use ($replacements): void {
            if (is_string($value)) {
                $value = strtr($value, $replacements);
            }
        });

        return $document;
    }
}
