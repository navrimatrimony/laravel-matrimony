@extends('public.pages.layout')

@section('page_title', __('public_pages.contact.title'))
@section('page_summary', __('public_pages.contact.summary'))
@section('og_title'){{ __('public_pages.contact.title') }} — {{ $siteName }}@endsection
@section('og_description'){{ __('public_pages.contact.meta_description') }}@endsection

@section('content')

    {{-- Reach us -------------------------------------------------------------
         Phone, email and working hours. Every value comes from $identity, which
         routes/web.php resolves once from LegalDocument::replacements(). Each
         card renders only when its fact is actually filled, so an unfilled
         config value shows nothing rather than an empty row. --}}
    <section class="px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto max-w-6xl">
            <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.contact.reach_title') }}</h2>
            <p class="{{ $devanagariClass }} mt-2 max-w-3xl text-sm leading-7 text-zinc-600">{{ __('public_pages.contact.reach_body') }}</p>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">

                @if ($identity['mobile'] !== '')
                    <a href="tel:{{ $identity['tel'] }}" class="group flex flex-col rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-red-300 hover:shadow-md">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-red-50 text-[color:var(--brand-red)]">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293a.75.75 0 0 1-.83.271 12.035 12.035 0 0 1-7.143-7.143.75.75 0 0 1 .271-.83l1.293-.97c.362-.271.527-.734.417-1.173L6.963 3.102A1.125 1.125 0 0 0 5.872 2.25H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                        </span>
                        <span class="{{ $devanagariClass }} mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.common.call') }}</span>
                        <span class="mt-1 text-lg font-bold text-[#201a1a] group-hover:text-[color:var(--brand-red)]">{{ $identity['mobile'] }}</span>
                    </a>
                @endif

                @if ($identity['email'] !== '')
                    <a href="mailto:{{ $identity['email'] }}" class="group flex flex-col rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-red-300 hover:shadow-md">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-red-50 text-[color:var(--brand-red)]">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                        </span>
                        <span class="{{ $devanagariClass }} mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.common.email') }}</span>
                        <span class="mt-1 break-words text-lg font-bold text-[#201a1a] group-hover:text-[color:var(--brand-red)]">{{ $identity['email'] }}</span>
                    </a>
                @endif

                @if ($identity['hours'] !== '')
                    <div class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-red-50 text-[color:var(--brand-red)]">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </span>
                        <span class="{{ $devanagariClass }} mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('public_pages.common.hours') }}</span>
                        <span class="mt-1 text-base font-bold leading-7 text-[#201a1a]">{{ $identity['hours'] }}</span>
                    </div>
                @endif
            </div>

            <p class="{{ $devanagariClass }} mt-5 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm leading-7 text-zinc-600">
                {{ __('public_pages.contact.support_note') }}
            </p>
        </div>
    </section>

    {{-- Registered office + legal identity ---------------------------------- --}}
    <section class="border-y border-zinc-200 bg-zinc-50 px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto grid max-w-6xl gap-8 lg:grid-cols-2">

            <div>
                <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.contact.office_title') }}</h2>
                <p class="{{ $devanagariClass }} mt-2 text-sm leading-7 text-zinc-600">{{ __('public_pages.contact.office_body') }}</p>

                @if ($identity['registered_address'] !== '')
                    <address class="mt-5 not-italic rounded-2xl border border-zinc-200 bg-white p-5 text-base leading-8 text-[#201a1a] shadow-sm">
                        @if ($identity['legal_name'] !== '')
                            <span class="block font-bold">{{ $identity['legal_name'] }}</span>
                        @endif
                        {{ $identity['registered_address'] }}
                    </address>
                @endif
            </div>

            <div>
                <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.contact.entity_title') }}</h2>

                <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        __('public_pages.common.entity') => $identity['legal_name'],
                        __('public_pages.common.llpin') => $identity['llpin'],
                        __('public_pages.common.incorporated_on') => $identity['incorporated_on'],
                        __('public_pages.common.jurisdiction') => $identity['jurisdiction'],
                        __('public_pages.common.website') => $identity['website'],
                    ] as $factLabel => $factValue)
                        @continue($factValue === '')
                        <div class="rounded-xl border border-zinc-200 bg-white px-4 py-4 shadow-sm">
                            <dt class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $factLabel }}</dt>
                            <dd class="mt-1 break-words text-sm font-medium text-zinc-800">{{ $factValue }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </section>

    {{-- Map — rendered only when an embed link has been configured. --}}
    @if ($mapEmbedUrl !== '')
        <section class="px-4 py-10 sm:px-6 sm:py-14">
            <div class="mx-auto max-w-6xl">
                <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.contact.map_title') }}</h2>
                <div class="mt-5 overflow-hidden rounded-2xl border border-zinc-200 shadow-sm">
                    <iframe
                        src="{{ $mapEmbedUrl }}"
                        title="{{ __('public_pages.contact.map_title') }}"
                        class="h-80 w-full"
                        style="border:0"
                        loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen
                    ></iframe>
                </div>
            </div>
        </section>
    @endif

    {{-- Grievance Officer — IT (Intermediary Guidelines) Rules 2021, Rule 3(2).
         Name, designation and contact details are statutory facts owned by
         config/legal.php; the timelines below are the same config values the
         grievance policy page quotes. --}}
    <section class="border-t border-zinc-200 px-4 py-10 sm:px-6 sm:py-14">
        <div class="mx-auto max-w-6xl">
            <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a] sm:text-2xl">{{ __('public_pages.contact.grievance_title') }}</h2>
            <p class="{{ $devanagariClass }} mt-2 max-w-3xl text-sm leading-7 text-zinc-600">{{ __('public_pages.contact.grievance_body') }}</p>

            <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    __('public_pages.contact.grievance_officer') => $identity['officer_name'],
                    __('public_pages.contact.grievance_designation') => $identity['officer_designation'],
                    __('public_pages.common.email') => $identity['officer_email'],
                    __('public_pages.common.call') => $identity['officer_phone'],
                    __('public_pages.common.hours') => $identity['officer_hours'],
                    __('public_pages.common.address') => $identity['officer_address'],
                ] as $officerLabel => $officerValue)
                    @continue($officerValue === '')
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-4">
                        <dt class="{{ $devanagariClass }} text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $officerLabel }}</dt>
                        <dd class="{{ $devanagariClass }} mt-1 break-words text-sm leading-6 text-zinc-800">{{ $officerValue }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @if ($identity['ack_hours'] !== '')
                    <p class="{{ $devanagariClass }} rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-7 text-emerald-900">
                        <span class="font-bold">{{ __('public_pages.contact.grievance_ack') }}:</span>
                        {{ __('public_pages.contact.grievance_ack_value', ['hours' => $identity['ack_hours']]) }}
                    </p>
                @endif
                @if ($identity['resolution_days'] !== '')
                    <p class="{{ $devanagariClass }} rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm leading-7 text-emerald-900">
                        <span class="font-bold">{{ __('public_pages.contact.grievance_resolution') }}:</span>
                        {{ __('public_pages.contact.grievance_resolution_value', ['days' => $identity['resolution_days']]) }}
                    </p>
                @endif
            </div>

            @if (! empty($legalLinks['grievance']['url']))
                <a href="{{ $legalLinks['grievance']['url'] }}" class="{{ $devanagariClass }} mt-6 inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2.5 text-sm font-bold text-[color:var(--brand-red)] transition hover:bg-red-50">
                    {{ __('public_pages.contact.grievance_cta') }}
                    <span aria-hidden="true">&rarr;</span>
                </a>
            @endif
        </div>
    </section>

    {{-- Social profiles — only when the product owner has published any. --}}
    @if (! empty($socialLinks))
        <section class="border-t border-zinc-200 bg-zinc-50 px-4 py-10 sm:px-6">
            <div class="mx-auto max-w-6xl">
                <h2 class="{{ $devanagariClass }} text-xl font-extrabold text-[#201a1a]">{{ __('public_pages.contact.social_title') }}</h2>
                <div class="mt-4 flex flex-wrap gap-3">
                    @foreach ($socialLinks as $socialLabel => $socialUrl)
                        <a href="{{ $socialUrl }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-700 shadow-sm transition hover:border-red-300 hover:text-[color:var(--brand-red)]">{{ $socialLabel }}</a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

@endsection
