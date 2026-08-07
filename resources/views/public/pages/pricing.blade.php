@extends('public.pages.layout')

@section('page_title', __('public_pages.pricing.title'))
@section('page_summary', __('public_pages.pricing.summary'))
@section('og_title'){{ __('public_pages.pricing.title') }} — {{ $siteName }}@endsection
@section('og_description'){{ __('public_pages.pricing.meta_description') }}@endsection

@section('hero_extra')
    <div class="flex flex-wrap gap-2">
        <span class="rounded-full border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700">{{ __('public_pages.pricing.currency_note') }}</span>
        @if ($allPricesTaxInclusive)
            <span class="rounded-full border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700">{{ __('public_pages.pricing.tax_note') }}</span>
        @endif
        <span class="rounded-full border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800">{{ __('public_pages.pricing.no_hidden_note') }}</span>
    </div>
@endsection

@section('content')

    {{-- Plan catalogue -------------------------------------------------------
         $pricingGroups is built in routes/web.php from `plans` filtered on
         is_active AND is_visible (both flags are mandatory — is_active alone
         would publish a tier the product owner has un-listed) with free-tier
         slugs rejected. Every amount below is App\Support\MoneyFormat output of
         the SSOT columns: MRP = plan_terms.price, payable = final_price
         (selling_price). The MRP is never printed as the price a member pays.

         Male-only and female-only tiers are shown as separate groups rather
         than merged. They are separate rows with separate prices in the
         database, and collapsing them would be a pricing-display decision the
         product owner has not taken. --}}

    @if ($hasPublishedPlans)

        @foreach ($pricingGroups as $group)
            <section class="px-4 py-10 sm:px-6 sm:py-12 {{ $loop->even ? 'border-y border-zinc-200 bg-zinc-50' : '' }}">
                <div class="mx-auto max-w-6xl">

                    @if ($group['label'] !== '')
                        <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ $group['label'] }}</h2>
                    @endif

                    <div class="mt-6 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        @foreach ($group['plans'] as $plan)
                            <article @class([
                                'relative flex flex-col rounded-2xl bg-white p-6 shadow-sm transition hover:shadow-md',
                                'border-2 border-[color:var(--brand-red)] ring-4 ring-red-50' => $plan['highlight'],
                                'border border-zinc-200' => ! $plan['highlight'],
                            ])>
                                @if ($plan['highlight'])
                                    <span class="{{ $devanagariClass }} absolute -top-3 left-6 rounded-full bg-[color:var(--brand-red)] px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-white">
                                        {{ __('homepage.popular') }}
                                    </span>
                                @endif

                                <h3 class="{{ $devanagariClass }} text-lg font-extrabold text-[#201a1a]">{{ $plan['name'] }}</h3>

                                @if ($plan['description'] !== '')
                                    <p class="{{ $devanagariClass }} mt-1.5 text-sm leading-6 text-zinc-600">{{ $plan['description'] }}</p>
                                @endif

                                {{-- Headline price: the cheapest published option for this
                                     plan, so "From" is literally true and no default-term
                                     resolution is reinvented here. --}}
                                @if ($plan['lead_payable'] !== null)
                                    <div class="mt-5">
                                        <span class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.from_label') }}</span>
                                        <div class="mt-1 flex flex-wrap items-baseline gap-2">
                                            <span class="text-3xl font-extrabold tracking-tight text-[color:var(--brand-red)]">{{ $plan['lead_payable'] }}</span>
                                            @if ($plan['lead_mrp'] !== null)
                                                <span class="text-base font-semibold text-zinc-400 line-through">{{ $plan['lead_mrp'] }}</span>
                                            @endif
                                        </div>
                                        <p class="{{ $devanagariClass }} mt-1 text-sm text-zinc-600">
                                            {{ __('public_pages.pricing.per_label') }}:
                                            <x-plan.duration-label :days="$plan['lead_days']" class="font-semibold text-zinc-800" />
                                        </p>
                                        @if ($plan['lead_discount'] !== null)
                                            <span class="{{ $devanagariClass }} mt-2 inline-block rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800">
                                                {{ __('public_pages.pricing.save_label') }} {{ $plan['lead_discount'] }}
                                            </span>
                                        @endif
                                    </div>
                                @endif

                                {{-- What the money buys. Lines come from
                                     Plan::catalogFeatureRowsForPricing() formatted by
                                     PlanQuotaCatalogFormatter — the same SSOT formatter the
                                     member app uses. No second formatter here. --}}
                                <div class="mt-6 border-t border-zinc-100 pt-5">
                                    <p class="{{ $devanagariClass }} text-xs font-bold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.includes_title') }}</p>

                                    @if (! empty($plan['features']))
                                        <ul class="mt-3 space-y-2" role="list">
                                            @foreach ($plan['features'] as $featureLine)
                                                <li class="flex gap-2.5" role="listitem">
                                                    <svg class="mt-1 h-4 w-4 shrink-0 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                                    <span class="{{ $devanagariClass }} min-w-0 break-words text-sm leading-6 text-zinc-700">{{ $featureLine }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <p class="{{ $devanagariClass }} mt-3 text-xs leading-6 text-zinc-500">
                                            {{ __('public_pages.pricing.includes_note') }}
                                        </p>
                                    @else
                                        <p class="{{ $devanagariClass }} mt-3 text-sm leading-6 text-zinc-500">{{ __('public_pages.pricing.no_features_note') }}</p>
                                    @endif
                                </div>

                                {{-- Every published duration for this plan, so the page shows
                                     the complete price list and not only the headline. --}}
                                @if (count($plan['terms']) > 1)
                                    <div class="mt-6 border-t border-zinc-100 pt-5">
                                        <p class="{{ $devanagariClass }} text-xs font-bold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.all_durations') }}</p>
                                        <div class="mt-3 overflow-x-auto">
                                            <table class="w-full min-w-[19rem] border-collapse text-sm">
                                                <thead>
                                                    <tr class="border-b border-zinc-200 text-left">
                                                        <th scope="col" class="{{ $devanagariClass }} py-2 pr-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.duration_label') }}</th>
                                                        <th scope="col" class="{{ $devanagariClass }} py-2 pr-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.mrp_label') }}</th>
                                                        <th scope="col" class="{{ $devanagariClass }} py-2 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.payable_label') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($plan['terms'] as $term)
                                                        <tr class="border-b border-zinc-100 last:border-0">
                                                            <th scope="row" class="{{ $devanagariClass }} py-2.5 pr-3 text-left font-medium text-zinc-700">
                                                                <x-plan.duration-label :days="$term['days']" />
                                                            </th>
                                                            <td class="py-2.5 pr-3 text-right text-zinc-400 {{ $term['discount'] !== null ? 'line-through' : '' }}">{{ $term['mrp'] }}</td>
                                                            <td class="py-2.5 text-right font-bold text-[#201a1a]">
                                                                {{ $term['payable'] }}
                                                                @if ($term['discount'] !== null)
                                                                    <span class="block text-[11px] font-semibold text-emerald-700">{{ __('public_pages.pricing.save_label') }} {{ $term['discount'] }}</span>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endforeach

    @else

        {{-- Honest empty state. The page still renders, still shows who to call,
             and never publishes a blank pricing screen to a reviewer. --}}
        <section class="px-4 py-14 sm:px-6">
            <div class="mx-auto max-w-3xl rounded-2xl border border-zinc-200 bg-zinc-50 p-8 text-center shadow-sm">
                <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a]">{{ __('public_pages.pricing.empty_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-600">{{ __('public_pages.pricing.empty_body') }}</p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    @if ($identity['mobile'] !== '')
                        <a href="tel:{{ $identity['tel'] }}" class="rounded-lg bg-[color:var(--brand-red)] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[color:var(--brand-red-dark)]">{{ $identity['mobile'] }}</a>
                    @endif
                    @if ($identity['email'] !== '')
                        <a href="mailto:{{ $identity['email'] }}" class="rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-bold text-[color:var(--brand-red)] transition hover:bg-red-50">{{ $identity['email'] }}</a>
                    @endif
                </div>
            </div>
        </section>

    @endif

    {{-- Free tier ------------------------------------------------------------
         Rendered only when a free catalogue row is actually active and visible,
         so this never claims a free tier the product does not offer. --}}
    @if ($freeTierPublished)
        <section class="border-t border-zinc-200 px-4 py-10 sm:px-6">
            <div class="mx-auto max-w-6xl rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="{{ $devanagariClass }} text-lg font-extrabold text-[#201a1a]">{{ __('public_pages.pricing.free_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-2 text-sm leading-7 text-zinc-600">{{ __('public_pages.pricing.free_body') }}</p>
            </div>
        </section>
    @endif

    {{-- How to buy ----------------------------------------------------------- --}}
    <section class="border-y border-zinc-200 bg-zinc-50 px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto max-w-6xl">
            <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.pricing.buy_title') }}</h2>

            <ol class="mt-6 grid gap-4 md:grid-cols-3" role="list">
                @foreach ((array) __('public_pages.pricing.buy_steps') as $stepIndex => $stepText)
                    <li class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm" role="listitem">
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-[color:var(--brand-red)] text-sm font-bold text-white">{{ $stepIndex + 1 }}</span>
                        <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ $stepText }}</p>
                    </li>
                @endforeach
            </ol>

            <div class="mt-6 flex flex-wrap gap-3">
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="{{ $devanagariClass }} rounded-lg bg-[color:var(--brand-red)] px-5 py-2.5 text-sm font-bold text-white transition hover:bg-[color:var(--brand-red-dark)]">{{ __('public_pages.pricing.buy_register_cta') }}</a>
                @endif
                @if (Route::has('login'))
                    <a href="{{ route('login') }}" class="{{ $devanagariClass }} rounded-lg border border-red-200 bg-white px-5 py-2.5 text-sm font-bold text-[color:var(--brand-red)] transition hover:bg-red-50">{{ __('public_pages.pricing.buy_login_cta') }}</a>
                @endif
            </div>
        </div>
    </section>

    {{-- Payments, billing and refunds ---------------------------------------- --}}
    <section class="px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto max-w-6xl">
            <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.pricing.payment_title') }}</h2>

            <dl class="mt-6 grid gap-3 sm:grid-cols-2">
                @if ($identity['payment_gateway'] !== '')
                    <div class="rounded-xl border border-zinc-200 bg-white px-4 py-4 shadow-sm">
                        <dt class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.payment_gateway') }}</dt>
                        <dd class="mt-1 text-sm font-medium text-zinc-800">{{ $identity['payment_gateway'] }}</dd>
                    </div>
                @endif

                <div class="rounded-xl border border-zinc-200 bg-white px-4 py-4 shadow-sm">
                    <dt class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.payment_delivery') }}</dt>
                    <dd class="{{ $devanagariClass }} mt-1 text-sm leading-6 text-zinc-800">
                        {{ __('public_pages.pricing.payment_delivery_value') }}
                        <a href="{{ route('public.shipping') }}" class="font-semibold text-[color:var(--brand-red)] hover:underline">{{ __('public_pages.shipping.title') }}</a>
                    </dd>
                </div>

                @if (! empty($legalLinks['refund']['url']))
                    <div class="rounded-xl border border-zinc-200 bg-white px-4 py-4 shadow-sm">
                        <dt class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.payment_refund') }}</dt>
                        <dd class="{{ $devanagariClass }} mt-1 text-sm leading-6 text-zinc-800">
                            {{ __('public_pages.pricing.payment_refund_value') }}
                            <a href="{{ $legalLinks['refund']['url'] }}" class="font-semibold text-[color:var(--brand-red)] hover:underline">{{ $legalLinks['refund']['label'] }}</a>
                        </dd>
                    </div>
                @endif

                @if (! empty($legalLinks['terms']['url']))
                    <div class="rounded-xl border border-zinc-200 bg-white px-4 py-4 shadow-sm sm:col-span-2">
                        <dt class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.pricing.payment_terms') }}</dt>
                        <dd class="{{ $devanagariClass }} mt-1 text-sm leading-6 text-zinc-800">
                            {{ __('public_pages.pricing.payment_terms_value') }}
                            <a href="{{ $legalLinks['terms']['url'] }}" class="font-semibold text-[color:var(--brand-red)] hover:underline">{{ $legalLinks['terms']['label'] }}</a>
                        </dd>
                    </div>
                @endif
            </dl>

            <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/70 p-5">
                <h3 class="{{ $devanagariClass }} text-base font-bold text-[#201a1a]">{{ __('public_pages.pricing.promise_title') }}</h3>
                <p class="{{ $devanagariClass }} mt-2 text-sm leading-7 text-zinc-800">{{ __('public_pages.pricing.promise_body') }}</p>
            </div>
        </div>
    </section>

@endsection
