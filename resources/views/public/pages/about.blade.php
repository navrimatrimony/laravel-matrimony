@extends('public.pages.layout')

@section('page_title', __('public_pages.about.title'))
@section('page_summary', __('public_pages.about.summary'))
@section('og_title'){{ __('public_pages.about.title') }} — {{ $siteName }}@endsection
@section('og_description'){{ __('public_pages.about.meta_description') }}@endsection

@section('content')

    {{-- The company ---------------------------------------------------------
         Only facts that exist in the repository are printed here: the legal
         entity, LLPIN, incorporation date, registered office and jurisdiction,
         all owned by config/legal.php and injected through $identity. No
         founding story, no member count, no award, no testimonial — nothing
         that cannot be sourced. --}}
    <section class="px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto grid max-w-6xl gap-8 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.about.entity_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-600">{{ __('public_pages.about.entity_body') }}</p>
            </div>

            <dl class="grid gap-3 sm:grid-cols-2 lg:col-span-3">
                @foreach ([
                    __('public_pages.common.entity') => $identity['legal_name'],
                    __('public_pages.common.llpin') => $identity['llpin'],
                    __('public_pages.common.incorporated_on') => $identity['incorporated_on'],
                    __('public_pages.common.jurisdiction') => $identity['jurisdiction'],
                    __('public_pages.common.address') => $identity['registered_address'],
                    __('public_pages.common.website') => $identity['website'],
                ] as $factLabel => $factValue)
                    @continue($factValue === '')
                    <div class="rounded-xl border border-zinc-200 bg-white px-4 py-4 shadow-sm">
                        <dt class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $factLabel }}</dt>
                        <dd class="mt-1 break-words text-sm font-medium leading-6 text-zinc-800">{{ $factValue }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- What the service does ------------------------------------------------ --}}
    <section class="border-y border-zinc-200 bg-zinc-50 px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto max-w-6xl">
            <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.about.service_title') }}</h2>
            <p class="{{ $devanagariClass }} mt-3 max-w-3xl text-sm leading-7 text-zinc-600">{{ __('public_pages.about.service_body') }}</p>

            <ul class="mt-6 grid gap-3 sm:grid-cols-2" role="list">
                @foreach ((array) __('public_pages.about.service_list') as $serviceItem)
                    <li class="flex gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-4 shadow-sm" role="listitem">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-[color:var(--brand-red)]" aria-hidden="true"></span>
                        <span class="{{ $devanagariClass }} text-sm leading-7 text-zinc-700">{{ $serviceItem }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Suchaks + how we make money ----------------------------------------- --}}
    <section class="px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-2">

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="{{ $devanagariClass }} text-lg font-extrabold text-[#201a1a]">{{ __('public_pages.about.suchak_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ __('public_pages.about.suchak_body') }}</p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="{{ $devanagariClass }} text-lg font-extrabold text-[#201a1a]">{{ __('public_pages.about.money_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ __('public_pages.about.money_body') }}</p>
                <a href="{{ route('public.pricing') }}" class="{{ $devanagariClass }} mt-4 inline-flex items-center gap-2 text-sm font-bold text-[color:var(--brand-red)] hover:underline">
                    {{ __('public_pages.about.money_cta') }}
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </div>
    </section>

    {{-- What we do NOT do ---------------------------------------------------
         Every line here restates a position already published in the
         Disclaimer and the Terms. It is repeated in plain language because a
         visitor should not have to open a policy to learn it. --}}
    <section class="border-y border-zinc-200 bg-zinc-50 px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto max-w-6xl">
            <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.about.limits_title') }}</h2>
            <p class="{{ $devanagariClass }} mt-3 max-w-3xl text-sm leading-7 text-zinc-600">{{ __('public_pages.about.limits_body') }}</p>

            <ul class="mt-6 space-y-3" role="list">
                @foreach ((array) __('public_pages.about.limits_list') as $limitItem)
                    <li class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-4" role="listitem">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                        <span class="{{ $devanagariClass }} text-sm leading-7 text-zinc-800">{{ $limitItem }}</span>
                    </li>
                @endforeach
            </ul>

            @if (! empty($legalLinks['disclaimer']['url']))
                <a href="{{ $legalLinks['disclaimer']['url'] }}" class="{{ $devanagariClass }} mt-6 inline-flex items-center gap-2 rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-bold text-[color:var(--brand-red)] transition hover:bg-red-50">
                    {{ __('public_pages.about.limits_cta') }}
                    <span aria-hidden="true">&rarr;</span>
                </a>
            @endif
        </div>
    </section>

    {{-- Safety and privacy + contact ---------------------------------------- --}}
    <section class="px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-2">

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="{{ $devanagariClass }} text-lg font-extrabold text-[#201a1a]">{{ __('public_pages.about.safety_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ __('public_pages.about.safety_body') }}</p>
                @if (! empty($legalLinks['privacy']['url']))
                    <a href="{{ $legalLinks['privacy']['url'] }}" class="{{ $devanagariClass }} mt-4 inline-flex items-center gap-2 text-sm font-bold text-[color:var(--brand-red)] hover:underline">
                        {{ $legalLinks['privacy']['label'] }}
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                @endif
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="{{ $devanagariClass }} text-lg font-extrabold text-[#201a1a]">{{ __('public_pages.about.contact_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-3 text-sm leading-7 text-zinc-700">{{ __('public_pages.about.contact_body') }}</p>

                <div class="mt-4 flex flex-wrap gap-3">
                    @if ($identity['mobile'] !== '')
                        <a href="tel:{{ $identity['tel'] }}" class="rounded-lg bg-[color:var(--brand-red)] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[color:var(--brand-red-dark)]">{{ $identity['mobile'] }}</a>
                    @endif
                    <a href="{{ route('public.contact') }}" class="{{ $devanagariClass }} rounded-lg border border-red-200 px-4 py-2.5 text-sm font-bold text-[color:var(--brand-red)] transition hover:bg-red-50">{{ __('public_pages.common.contact_us') }}</a>
                </div>
            </div>
        </div>
    </section>

@endsection
