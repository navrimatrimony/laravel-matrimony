{{--
    Site-wide public footer — the ONE footer every page shell renders.

    Included by layouts/app.blade.php, layouts/guest.blade.php,
    layouts/bulk-register.blade.php and public/welcome.blade.php. Adding it to a
    new shell is one @include; there is no second copy to keep in sync.

    OWNERSHIP (frozen no-duplicate rule, docs/FIELD-OWNERSHIP-MAP.md:120)
    Not one company fact is typed into this file. Facts arrive through the single
    documented join point, App\Support\LegalDocument::replacements(), whose rule
    is: config/legal.php wins, and the DB-backed SiteIdentityService only fills a
    value config left as an unfilled [[TOKEN]]. Change config/legal.php once and
    every footer on the site changes. Brand name, socials and the copyright line
    stay owned by SiteIdentityService, which is their existing owner.

    Labels are lang keys. New ones live in lang/{en,mr}/footer.php; ones that
    already had an owner are read from that owner (homepage.*, nav.*, legal.*).

    Optional include data:
      footerBottomInset (bool) — add bottom padding so the mobile sticky nav
                                 cannot sit on top of the last footer row.
--}}
@php
    $siteIdentityFooter = app(\App\Services\SiteIdentityService::class);
    $footerBottomInset = (bool) ($footerBottomInset ?? false);
    $footerDevanagari = \App\Support\LocalizedText::isMarathiLoose() ? 'font-devanagari' : '';

    // --- Company facts: read, never written here ---------------------------
    $footerFacts = \App\Support\LegalDocument::replacements();

    // An unfilled [[TOKEN]] is a missing fact, not a fact. It is allowed to shout
    // on the admin-facing strip of a legal page; it must never reach the footer.
    $footerFact = static function (string $token) use ($footerFacts): string {
        $value = trim((string) ($footerFacts[$token] ?? ''));

        return \App\Support\LegalDocument::isUnfilled($value) ? '' : $value;
    };

    $footerBrandName = trim((string) $siteIdentityFooter->siteNameForLocale());
    $footerLegalName = $footerFact(':legal_name');
    $footerLlpin = $footerFact(':llpin');
    $footerRegisteredAddress = $footerFact(':registered_address');
    $footerSupportEmail = $footerFact(':support_email');
    $footerPhone = $footerFact(':contact_mobile');
    $footerOfficerName = $footerFact(':officer_name');
    $footerOfficerHours = $footerFact(':officer_hours');

    // tel: needs a dialable string. The configured tel form is preferred; when an
    // admin has overridden the displayed number we derive the href from what is
    // actually shown, so the two can never disagree.
    $footerPhoneHref = '';
    if ($footerPhone !== '') {
        $configuredMobile = trim((string) config('legal.contact.mobile'));
        $configuredTel = trim((string) config('legal.contact.mobile_tel'));
        $footerPhoneHref = ($footerPhone === $configuredMobile && $configuredTel !== '')
            ? $configuredTel
            : (string) preg_replace('/[^0-9+]/', '', $footerPhone);
    }

    $footerCopyright = trim((string) $siteIdentityFooter->copyrightText());
    if ($footerCopyright === '') {
        $footerCopyright = '© '.date('Y').' '.($footerLegalName !== '' ? $footerLegalName : $footerBrandName);
    }

    // --- Link resolution ----------------------------------------------------
    // Route names are resolved, never URLs hard-coded. /pricing, /about,
    // /contact and /shipping are being registered separately; each candidate list
    // is tried in order and the link is simply omitted until one resolves, so a
    // half-deployed route can never render a broken link or 500 a page.
    $footerResolveRoute = static function (array $names): ?string {
        foreach ($names as $name) {
            if (! \Illuminate\Support\Facades\Route::has($name)) {
                continue;
            }

            try {
                return route($name);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    };

    $footerPricingUrl = $footerResolveRoute(['pricing', 'pricing.index', 'public.pricing', 'plans.public', 'legal.pricing']);

    // Until the public pricing page exists, signed-in members keep their existing
    // link to /plans. Guests never get it: /plans is auth-only and would bounce a
    // signed-out visitor (and a payment-gateway reviewer) into the login wall.
    if ($footerPricingUrl === null && auth()->check()) {
        $footerPricingUrl = $footerResolveRoute(['plans.index']);
    }
    $footerAboutUrl = $footerResolveRoute(['about', 'about.index', 'public.about', 'legal.about']);
    $footerContactUrl = $footerResolveRoute(['contact', 'contact.index', 'public.contact', 'legal.contact']);
    $footerShippingUrl = $footerResolveRoute(['shipping', 'shipping.index', 'public.shipping', 'legal.shipping', 'shipping-policy']);

    // Every URL is emitted at most once across the whole footer. If the new pages
    // land inside the legal document registry they appear in the Company column
    // and are skipped by the Legal column, instead of being listed twice.
    $footerSeenUrls = [];
    $footerClaim = static function (?string $url) use (&$footerSeenUrls): bool {
        $url = trim((string) $url);

        if ($url === '' || isset($footerSeenUrls[$url])) {
            return false;
        }

        $footerSeenUrls[$url] = true;

        return true;
    };

    $footerCompanyLinks = [];
    foreach ([
        [url('/'), __('nav.home')],
        [$footerAboutUrl, __('footer.about_us')],
        [$footerPricingUrl, __('footer.pricing')],
        [$footerContactUrl, __('footer.contact_us')],
        [\Illuminate\Support\Facades\Route::has('suchak.home') ? route('suchak.home') : null, __('homepage.footer_suchak')],
        // Guests are never sent to /profiles or /plans: both sit behind auth and
        // bounce a signed-out visitor into the login wall.
        [auth()->check() && \Illuminate\Support\Facades\Route::has('matrimony.profiles.index') ? route('matrimony.profiles.index') : null, __('homepage.footer_partner_search')],
        [auth()->guest() && \Illuminate\Support\Facades\Route::has('register') ? route('register') : null, __('homepage.register')],
        [auth()->guest() && \Illuminate\Support\Facades\Route::has('login') ? route('login') : null, __('homepage.login')],
    ] as [$footerLinkUrl, $footerLinkLabel]) {
        if ($footerClaim($footerLinkUrl)) {
            $footerCompanyLinks[] = ['url' => $footerLinkUrl, 'label' => $footerLinkLabel];
        }
    }

    $footerLegalLinks = [];
    foreach (\App\Support\LegalDocument::links() as $footerLegalLink) {
        if ($footerClaim($footerLegalLink['url'] ?? null)) {
            $footerLegalLinks[] = ['url' => $footerLegalLink['url'], 'label' => $footerLegalLink['label']];
        }
    }
    if ($footerClaim($footerShippingUrl)) {
        $footerLegalLinks[] = ['url' => $footerShippingUrl, 'label' => __('footer.shipping')];
    }

    $footerSocialLinks = array_filter([
        'Facebook' => $siteIdentityFooter->get('facebook_url'),
        'Instagram' => $siteIdentityFooter->get('instagram_url'),
        'YouTube' => $siteIdentityFooter->get('youtube_url'),
        'LinkedIn' => $siteIdentityFooter->get('linkedin_url'),
        'X' => $siteIdentityFooter->get('x_url'),
    ], static fn ($url): bool => filled($url));

    // Heroicons (outline, 24). Multiple paths separated by "|".
    $footerIconPaths = [
        'phone' => 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z',
        'mail' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
        'pin' => 'M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z|M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z',
        'clock' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'user' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
    ];
@endphp

<footer
    role="contentinfo"
    aria-label="{{ __('footer.landmark') }}"
    class="mt-auto bg-zinc-950 text-sm text-zinc-400 {{ $footerBottomInset ? 'pb-24 md:pb-0' : '' }}"
>
    <div class="h-px w-full bg-gradient-to-r from-transparent via-red-800/70 to-transparent" aria-hidden="true"></div>

    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:py-14">
        {{-- Four equal columns on desktop, two on tablet, one on a phone.

             This used to be a 12-track grid with lg:col-span-4/3/2/3 children.
             Those utilities are not in the compiled stylesheet, so on a wide
             screen every column fell back to one track of twelve: the whole
             footer crushed into the left third and left two thirds of black
             empty. Equal columns need no span classes at all, so there is
             nothing here that can go missing again. --}}
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-4 lg:gap-8">

            {{-- Identity --}}
            <div>
                <p class="{{ $footerDevanagari }} text-lg font-bold tracking-tight text-white">{{ $footerBrandName }}</p>

                @if ($footerLegalName !== '' && $footerLegalName !== $footerBrandName)
                    <p class="mt-1 text-xs leading-6 text-zinc-500">{{ $footerLegalName }}</p>
                @endif

                @if ($footerLlpin !== '')
                    <p class="mt-1 text-xs leading-6 text-zinc-600">
                        <span class="font-semibold uppercase tracking-wide">{{ __('footer.llpin') }}</span>
                        <span class="ml-1 font-mono text-zinc-500">{{ $footerLlpin }}</span>
                    </p>
                @endif

                @if ($footerRegisteredAddress !== '')
                    <div class="mt-5 flex items-start gap-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-red-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            @foreach (explode('|', $footerIconPaths['pin']) as $footerIconPath)
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $footerIconPath }}" />
                            @endforeach
                        </svg>
                        <p class="text-xs leading-6 text-zinc-500">
                            <span class="block font-semibold uppercase tracking-wide text-zinc-600">{{ __('footer.registered_office') }}</span>
                            <span class="mt-0.5 block text-zinc-400">{{ $footerRegisteredAddress }}</span>
                        </p>
                    </div>
                @endif

                <p class="{{ $footerDevanagari }} mt-5 max-w-md text-xs leading-6 text-zinc-600">{{ __('homepage.footer_disclaimer') }}</p>
            </div>

            {{-- Contact --}}
            <div>
                <h2 class="{{ $footerDevanagari }} text-[11px] font-bold uppercase tracking-[0.14em] text-zinc-500">{{ __('homepage.footer_contact') }}</h2>
                <span class="mt-2 block h-0.5 w-8 rounded-full bg-red-800/80" aria-hidden="true"></span>

                <ul class="mt-4 space-y-3">
                    @if ($footerPhone !== '')
                        <li>
                            <a href="tel:{{ $footerPhoneHref }}" class="group flex items-start gap-2.5 text-zinc-300 transition hover:text-white">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-600 transition group-hover:text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $footerIconPaths['phone'] }}" />
                                </svg>
                                <span>
                                    <span class="{{ $footerDevanagari }} block text-[11px] uppercase tracking-wide text-zinc-600">{{ __('footer.call_us') }}</span>
                                    <span class="block font-semibold">{{ $footerPhone }}</span>
                                </span>
                            </a>
                        </li>
                    @endif

                    @if ($footerSupportEmail !== '')
                        <li>
                            <a href="mailto:{{ $footerSupportEmail }}" class="group flex items-start gap-2.5 text-zinc-300 transition hover:text-white">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-600 transition group-hover:text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $footerIconPaths['mail'] }}" />
                                </svg>
                                <span>
                                    <span class="{{ $footerDevanagari }} block text-[11px] uppercase tracking-wide text-zinc-600">{{ __('footer.email_us') }}</span>
                                    <span class="block break-all font-semibold">{{ $footerSupportEmail }}</span>
                                </span>
                            </a>
                        </li>
                    @endif

                    @if ($footerOfficerHours !== '')
                        <li class="flex items-start gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $footerIconPaths['clock'] }}" />
                            </svg>
                            <span>
                                <span class="{{ $footerDevanagari }} block text-[11px] uppercase tracking-wide text-zinc-600">{{ __('footer.support_hours') }}</span>
                                <span class="block text-zinc-400">{{ $footerOfficerHours }}</span>
                            </span>
                        </li>
                    @endif

                    @if ($footerOfficerName !== '')
                        <li class="flex items-start gap-2.5">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $footerIconPaths['user'] }}" />
                            </svg>
                            <span>
                                <span class="{{ $footerDevanagari }} block text-[11px] uppercase tracking-wide text-zinc-600">{{ __('footer.grievance_officer') }}</span>
                                <span class="block text-zinc-400">{{ $footerOfficerName }}</span>
                            </span>
                        </li>
                    @endif
                </ul>
            </div>

            {{-- Company --}}
            <div>
                <h2 class="{{ $footerDevanagari }} text-[11px] font-bold uppercase tracking-[0.14em] text-zinc-500">{{ __('footer.company') }}</h2>
                <span class="mt-2 block h-0.5 w-8 rounded-full bg-red-800/80" aria-hidden="true"></span>

                <ul class="mt-4 space-y-2.5">
                    @foreach ($footerCompanyLinks as $footerCompanyLink)
                        <li>
                            <a href="{{ $footerCompanyLink['url'] }}" class="{{ $footerDevanagari }} text-zinc-300 underline-offset-4 transition hover:text-white hover:underline">{{ $footerCompanyLink['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Legal --}}
            <div>
                <h2 class="{{ $footerDevanagari }} text-[11px] font-bold uppercase tracking-[0.14em] text-zinc-500">{{ __('homepage.footer_legal') }}</h2>
                <span class="mt-2 block h-0.5 w-8 rounded-full bg-red-800/80" aria-hidden="true"></span>

                <ul class="mt-4 space-y-2.5">
                    @foreach ($footerLegalLinks as $footerLegalLink)
                        <li>
                            <a href="{{ $footerLegalLink['url'] }}" class="{{ $footerDevanagari }} text-zinc-300 underline-offset-4 transition hover:text-white hover:underline">{{ $footerLegalLink['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="mt-10 flex flex-col gap-4 border-t border-white/10 pt-6 text-xs text-zinc-600 sm:flex-row sm:items-center sm:justify-between">
            <p>{{ $footerCopyright }}</p>

            @if (! empty($footerSocialLinks))
                <div class="flex flex-wrap items-center gap-2">
                    <span class="{{ $footerDevanagari }} mr-1 uppercase tracking-wide">{{ __('footer.follow_us') }}</span>
                    @foreach ($footerSocialLinks as $footerSocialLabel => $footerSocialUrl)
                        <a
                            href="{{ $footerSocialUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-full border border-white/10 px-3 py-1 text-zinc-400 transition hover:border-red-800/70 hover:text-white"
                        >{{ $footerSocialLabel }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</footer>
