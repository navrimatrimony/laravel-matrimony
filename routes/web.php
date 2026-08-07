<?php

use App\Models\Caste;
use App\Models\SubCaste;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — Phase 1 surface loaders
|--------------------------------------------------------------------------
| Order: public → member → suchak → admin → admin-suchak → auth, then legacy web JSON.
| Admin intake suggestion queue: routes/web/admin.php → prefix admin/intake (names admin.intake.*).
| Member matches: routes/web/member.php → GET /matches, GET /profiles/{id}/matches.
| Member plans: GET /plans + coupon validate are auth-only (avoids guest catalog / gender edge cases); POST /subscribe uses auth + card onboarding.
| Match boost: routes/web/admin.php → GET/PUT /admin/match-boost; MatchingService applies boosts after base score.
|--------------------------------------------------------------------------
*/

require __DIR__.'/web/public.php';
require __DIR__.'/web/member.php';
require __DIR__.'/web/suchak.php';
require __DIR__.'/web/admin.php';
require __DIR__.'/web/admin-suchak.php';
require __DIR__.'/auth.php';

use App\Http\Controllers\Api\MobileBiodataExportApiController;
use App\Http\Controllers\MobilePlanCheckoutBridgeController;
use App\Http\Controllers\PlansController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Middleware\EnforceCardOnboarding;

Route::get('/mobile/biodata/export', [MobileBiodataExportApiController::class, 'download'])
    ->middleware(['throttle:20,1'])
    ->name('mobile.biodata.export.download');

Route::get('/mobile/plans/checkout', MobilePlanCheckoutBridgeController::class)
    ->middleware(['throttle:20,1'])
    ->name('mobile.plans.checkout.bridge');

Route::middleware('auth')->group(function () {
    Route::get('/plans', [PlansController::class, 'index'])->name('plans.index');
    Route::post('/plans/coupon/validate', [PlansController::class, 'validateCoupon'])->name('plans.coupon.validate');
});
Route::match(['get', 'post'], '/subscribe', [SubscriptionController::class, 'subscribe'])
    ->middleware(['auth', EnforceCardOnboarding::class])
    ->name('plans.subscribe');

/*
|--------------------------------------------------------------------------
| Public, no-auth information pages
|--------------------------------------------------------------------------
| /pricing, /contact, /about and /shipping. A payment-gateway reviewer, an app
| store reviewer and a first-time visitor all read these signed out, so none of
| them may sit behind `auth` — note that /plans above deliberately does, which
| is exactly why /pricing exists as a separate guest-safe catalogue.
|
| Ownership (frozen no-duplicate rule):
|   company + contact facts -> config/legal.php, read through the ONE join point
|                              App\Support\LegalDocument::replacements(). Not one
|                              phone number, email, address or entity name is
|                              written in a view or a lang file.
|   prices                  -> `plans` / `plan_terms`; MRP = price, payable =
|                              final_price (selling_price). Never price x (1-%).
|   money / percent text    -> App\Support\MoneyFormat, App\Support\PercentDisplay
|                              (Latin digits and Indian grouping by construction).
|   page copy               -> lang/{en,mr}/public_pages.php, voice only.
*/

use App\Models\Plan;
use App\Models\PlanTerm;
use App\Support\LegalDocument;
use App\Support\LocalizedText;
use App\Support\MobileNumber;
use App\Support\MoneyFormat;
use App\Support\PercentDisplay;
use App\Support\PlanQuotaCatalogFormatter;
use App\Services\SiteIdentityService;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the shared shell (resources/views/public/pages/layout.blade.php)
 * needs, resolved once per request so the four pages cannot drift apart.
 */
$publicInfoPageShell = static function (string $activePageKey): array {
    $siteIdentity = app(SiteIdentityService::class);
    $siteIdentitySettings = $siteIdentity->all();

    // The single join point between config/legal.php and SiteIdentityService.
    // Its documented precedence (config wins; the admin setting fills an unfilled
    // [[TOKEN]] only) applies here unchanged — these pages add no second rule.
    $tokens = LegalDocument::replacements();

    $fact = static function (string $token) use ($tokens): string {
        $value = trim((string) ($tokens[$token] ?? ''));

        // A still-unfilled [[TOKEN]] reads as absent, so the block around it
        // simply does not render rather than publishing a placeholder.
        return LegalDocument::isUnfilled($value) ? '' : $value;
    };

    $mobile = $fact(':contact_mobile');
    $normalizedMobile = MobileNumber::normalize($mobile);
    $contactHours = $fact(':contact_hours');

    $jurisdiction = implode(', ', array_filter(
        [$fact(':jurisdiction_city'), $fact(':jurisdiction_state')],
        static fn (string $part): bool => $part !== '',
    ));

    $identity = [
        'legal_name' => $fact(':legal_name'),
        'brand_name' => $fact(':brand_name'),
        'website' => $fact(':website'),
        'llpin' => $fact(':llpin'),
        'incorporated_on' => $fact(':incorporated_on'),
        'registered_address' => $fact(':registered_address'),
        'jurisdiction' => $jurisdiction,
        'mobile' => $mobile,
        // Dialling form derived from the published number itself, so an admin
        // override of the public phone moves the tel: link with it.
        'tel' => $normalizedMobile !== null
            ? '+91'.$normalizedMobile
            : trim((string) config('legal.contact.mobile_tel', '')),
        'email' => $fact(':support_email'),
        // Forward-compatible: the day config/legal.php gains a dedicated public
        // support-hours fact and a :contact_hours token, these pages pick it up
        // with no edit here. Until then the published officer hours are that fact.
        'hours' => $contactHours !== '' ? $contactHours : $fact(':officer_hours'),
        'officer_name' => $fact(':officer_name'),
        'officer_designation' => $fact(':officer_designation'),
        'officer_email' => $fact(':officer_email'),
        'officer_phone' => $fact(':officer_phone'),
        'officer_address' => $fact(':officer_address'),
        'officer_hours' => $fact(':officer_hours'),
        'ack_hours' => $fact(':ack_hours'),
        'resolution_days' => $fact(':resolution_days'),
        'payment_gateway' => $fact(':payment_gateway'),
    ];

    return [
        'siteIdentity' => $siteIdentity,
        'siteIdentitySettings' => $siteIdentitySettings,
        'siteName' => $siteIdentity->siteNameForLocale(),
        'devanagariClass' => LocalizedText::isMarathiLoose() ? 'font-devanagari' : '',
        'identity' => $identity,
        'legalLinks' => LegalDocument::links(),
        'activePageKey' => $activePageKey,
        'publicPageLinks' => [
            'pricing' => ['url' => route('public.pricing'), 'label' => __('public_pages.pricing.title')],
            'about' => ['url' => route('public.about'), 'label' => __('public_pages.about.title')],
            'contact' => ['url' => route('public.contact'), 'label' => __('public_pages.contact.title')],
            'shipping' => ['url' => route('public.shipping'), 'label' => __('public_pages.shipping.title')],
        ],
        'socialLinks' => array_filter([
            'Facebook' => $siteIdentitySettings['facebook_url'] ?? null,
            'Instagram' => $siteIdentitySettings['instagram_url'] ?? null,
            'YouTube' => $siteIdentitySettings['youtube_url'] ?? null,
            'LinkedIn' => $siteIdentitySettings['linkedin_url'] ?? null,
            'X' => $siteIdentitySettings['x_url'] ?? null,
        ]),
    ];
};

Route::get('/pricing', function () use ($publicInfoPageShell) {
    // BOTH flags are mandatory. is_active alone (what the auth-only /plans page
    // filters on) would publish a tier the product owner has un-listed.
    $publishedPlans = Schema::hasTable('plans')
        ? Plan::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->with([
                // Constrained so `$plan->terms` already holds only the published
                // durations — plan_terms.is_visible defaults to false, i.e. a term
                // is opt-in and a hidden one must never reach this page.
                'terms' => static fn ($query) => $query->where('is_visible', true)->orderBy('sort_order')->orderBy('id'),
                'quotaPolicies',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
        : collect();

    $freeTierPublished = $publishedPlans
        ->contains(static fn (Plan $plan): bool => Plan::isFreeCatalogSlug((string) $plan->slug));

    $paidPlans = $publishedPlans
        ->reject(static fn (Plan $plan): bool => Plan::isFreeCatalogSlug((string) $plan->slug))
        ->values();

    $presentPlan = static function (Plan $plan): ?array {
        $terms = $plan->terms->values();

        // A paid tier with no published duration has no price to state, and a
        // priceless card on a pricing page is worse than no card.
        if ($terms->isEmpty()) {
            return null;
        }

        $termDays = static fn (PlanTerm $term): int => (int) ($term->duration_days
            ?: PlanTerm::durationDaysFor((string) $term->billing_key));

        // Headline = the cheapest published option, so "From" is literally true.
        // Deliberately NOT the default billing term: resolving that would mean
        // re-implementing MobilePlanApiController's private defaultTerm().
        $leadTerm = $terms->sortBy(static fn (PlanTerm $term): float => (float) $term->final_price)->first();
        $leadDays = $termDays($leadTerm);

        $termRows = $terms->map(static function (PlanTerm $term) use ($termDays): array {
            return [
                'days' => $termDays($term),
                'mrp' => MoneyFormat::amount($term->price),
                'payable' => MoneyFormat::amount($term->final_price),
                'discount' => $term->hasActiveDiscount()
                    ? PercentDisplay::display($term->displayDiscountPercent(), 0)
                    : null,
            ];
        })->all();

        // Final catalog lines for the headline term, produced by the same SSOT
        // formatter the member app calls. The quota bonus and the duration
        // multiplier are already applied here — nothing downstream re-multiplies.
        $featureLines = $plan->catalogFeatureRowsForPricing()
            ->filter(static fn (object $feature): bool => (bool) ($feature->included ?? false))
            ->filter(static fn (object $feature): bool => is_array($feature->catalog_quota_payload ?? null))
            ->map(static fn (object $feature): string => PlanQuotaCatalogFormatter::catalogLineFromPayload(
                (string) $feature->key,
                (array) $feature->catalog_quota_payload,
                (int) ($leadTerm->quota_bonus_percent ?? 0),
                (string) $leadTerm->billing_key,
                PlanTerm::quotaDurationMultiplierFor((string) $leadTerm->billing_key, $leadDays),
            ))
            ->values()
            ->all();

        return [
            'name' => $plan->localizedName(),
            'description' => trim((string) $plan->description),
            'highlight' => (bool) $plan->highlight,
            'lead_payable' => MoneyFormat::amount($leadTerm->final_price),
            'lead_mrp' => $leadTerm->hasActiveDiscount() ? MoneyFormat::amount($leadTerm->price) : null,
            'lead_days' => $leadDays,
            'lead_discount' => $leadTerm->hasActiveDiscount()
                ? PercentDisplay::display($leadTerm->displayDiscountPercent(), 0)
                : null,
            'terms' => $termRows,
            'features' => $featureLines,
        ];
    };

    // Grouped by the audience the plan row is actually sold to. Male-only and
    // female-only tiers stay separate: they are separate rows with separate
    // prices, and merging them would be a pricing-display decision.
    $groupLabels = [
        'all' => '',
        'male' => __('subscriptions.admin_plan_gender_male'),
        'female' => __('subscriptions.admin_plan_gender_female'),
    ];

    $pricingGroups = [];
    foreach ($paidPlans as $plan) {
        $audience = strtolower(trim((string) ($plan->applies_to_gender ?? 'all')));
        $audience = array_key_exists($audience, $groupLabels) ? $audience : 'all';

        $presented = $presentPlan($plan);
        if ($presented === null) {
            continue;
        }

        $pricingGroups[$audience]['label'] = $groupLabels[$audience];
        $pricingGroups[$audience]['plans'][] = $presented;
    }
    $pricingGroups = array_values($pricingGroups);

    return view('public.pages.pricing', $publicInfoPageShell('pricing') + [
        'pricingGroups' => $pricingGroups,
        'hasPublishedPlans' => $pricingGroups !== [],
        'freeTierPublished' => $freeTierPublished,
        // Stated only when every published plan really is tax-inclusive.
        'allPricesTaxInclusive' => $paidPlans->isNotEmpty()
            && $paidPlans->every(static fn (Plan $plan): bool => (bool) $plan->gst_inclusive),
    ]);
})->name('public.pricing');

Route::get('/contact', function () use ($publicInfoPageShell) {
    return view('public.pages.contact', $publicInfoPageShell('contact') + [
        // Optional; empty until the product owner publishes an embed link.
        'mapEmbedUrl' => trim((string) app(SiteIdentityService::class)->get('google_maps_embed_link', '')),
    ]);
})->name('public.contact');

Route::get('/about', function () use ($publicInfoPageShell) {
    return view('public.pages.about', $publicInfoPageShell('about'));
})->name('public.about');

Route::get('/shipping', function () use ($publicInfoPageShell) {
    // The no-physical-delivery statement is NOT rewritten here. It is quoted
    // live from clause 2 of the Refund and Cancellation Policy, so the two pages
    // cannot contradict each other and a policy reword carries across on its own.
    // Clause numbers are Latin digits in every locale (frozen product rule),
    // which makes the "2." prefix a locale-independent anchor.
    $refundClause = null;
    foreach ((array) (LegalDocument::content('refund')['sections'] ?? []) as $section) {
        $heading = trim((string) ($section['heading'] ?? ''));
        if (! preg_match('/^2\.\s/', $heading)) {
            continue;
        }

        $body = array_values(array_filter(
            array_map(static fn ($paragraph): string => trim((string) $paragraph), (array) ($section['body'] ?? [])),
            static fn (string $paragraph): bool => $paragraph !== '',
        ));

        if ($body !== []) {
            $refundClause = ['number' => '2', 'heading' => $heading, 'body' => $body];
        }

        break;
    }

    $shell = $publicInfoPageShell('shipping');

    return view('public.pages.shipping', $shell + [
        'refundClause' => $refundClause,
        'refundPolicyLink' => $shell['legalLinks']['refund'] ?? [],
    ]);
})->name('public.shipping');

// Temporary debug route — Phase-5 Day-12 verification. Remove before production.

Route::get('/api/castes/{religionId}', function ($religionId) {
    return Caste::where('religion_id', $religionId)
        ->where('is_active', true)
        ->orderBy('label')
        ->get(['id', 'label', 'label_en', 'label_mr'])
        ->map(function (\App\Models\Caste $c) {
            return [
                'id' => $c->id,
                'label' => $c->display_label,
                'label_en' => $c->label_en ?? $c->label,
                'label_mr' => $c->label_mr,
            ];
        });
});

Route::get('/api/subcastes/{casteId}', function ($casteId) {
    return SubCaste::where('caste_id', $casteId)
        ->where('is_active', true)
        ->where('status', 'approved')
        ->orderBy('label')
        ->get(['id', 'label', 'label_en', 'label_mr'])
        ->map(function (\App\Models\SubCaste $s) {
            return [
                'id' => $s->id,
                'label' => $s->display_label,
                'label_en' => $s->label_en ?? $s->label,
                'label_mr' => $s->label_mr,
            ];
        });
});
