@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8">
    <div class="mb-6">
        <a href="{{ route('suchak.marketplace.index') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-300 dark:hover:text-indigo-200">Back to marketplace</a>
        <div class="mt-3 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $publicProfile['account']['name'] }}</h1>
                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ $publicProfile['account']['verified_badge'] }}</span>
                </div>
                @if ($publicProfile['account']['office_name'])
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $publicProfile['account']['office_name'] }}</p>
                @endif
                <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Public Suchak profile with factual service information. Requests are routed through platform records and candidate contact remains masked.
                </p>
            </div>
            <dl class="rounded-lg border border-gray-200 bg-white p-4 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div><dt class="inline font-semibold text-gray-900 dark:text-gray-100">Type:</dt> <dd class="inline text-gray-700 dark:text-gray-300">{{ $publicProfile['account']['business_type'] }}</dd></div>
                <div class="mt-2"><dt class="inline font-semibold text-gray-900 dark:text-gray-100">Area:</dt> <dd class="inline text-gray-700 dark:text-gray-300">{{ $publicProfile['area']['line'] }}</dd></div>
                <div class="mt-2"><dt class="inline font-semibold text-gray-900 dark:text-gray-100">Public profiles:</dt> <dd class="inline text-gray-700 dark:text-gray-300">{{ $publicProfile['metrics']['public_representations_count'] }}</dd></div>
            </dl>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    @if ($publicProfile['communities']->isNotEmpty())
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ($publicProfile['communities'] as $community)
                <span class="rounded-full border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">{{ $community }}</span>
            @endforeach
        </div>
    @endif

    <section class="mb-8">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Published service cards</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @forelse ($publicProfile['packages'] as $package)
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $package['name'] }}</h3>
                            @if ($package['description'])
                                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $package['description'] }}</p>
                            @endif
                        </div>
                        <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm font-semibold text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ $package['price_label'] }}</p>
                    </div>
                    <dl class="mt-4 grid gap-2 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-2">
                        <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Stages</dt><dd>{{ $package['stage_count'] }}</dd></div>
                        <div><dt class="font-semibold text-gray-900 dark:text-gray-100">Deliverables</dt><dd>{{ $package['deliverable_count'] }}</dd></div>
                    </dl>
                    @if ($package['stages']->isNotEmpty())
                        <div class="mt-4">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Stage names</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($package['stages'] as $stage)
                                    <span class="rounded-full border border-gray-200 px-2.5 py-1 text-xs text-gray-700 dark:border-gray-700 dark:text-gray-300">{{ $stage }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 md:col-span-2">
                    No published service package listed.
                </div>
            @endforelse
        </div>
    </section>

    <section>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Masked represented profiles</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @forelse ($publicProfile['representations'] as $candidate)
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $candidate['candidate_reference'] }}</p>
                            <h3 class="mt-1 text-base font-semibold text-gray-900 dark:text-gray-100">Masked candidate profile</h3>
                        </div>
                        <span class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">Contact masked</span>
                    </div>
                    <dl class="mt-4 grid gap-2 text-sm text-gray-700 dark:text-gray-300 sm:grid-cols-2">
                        <div><dt class="inline font-semibold">Age:</dt> <dd class="inline">{{ $candidate['basic']['age_range'] ?? 'Not available' }}</dd></div>
                        <div><dt class="inline font-semibold">Height:</dt> <dd class="inline">{{ $candidate['basic']['height_range'] ?? 'Not available' }}</dd></div>
                        <div><dt class="inline font-semibold">Education:</dt> <dd class="inline">{{ $candidate['education']['highest'] ?? 'Not available' }}</dd></div>
                        <div><dt class="inline font-semibold">Occupation:</dt> <dd class="inline">{{ $candidate['occupation']['broad'] ?? 'Not available' }}</dd></div>
                        <div><dt class="inline font-semibold">Area:</dt> <dd class="inline">{{ collect([$candidate['location']['city'] ?? null, $candidate['location']['district'] ?? null])->filter()->implode(', ') ?: 'Broad area unavailable' }}</dd></div>
                        <div><dt class="inline font-semibold">Community:</dt> <dd class="inline">{{ collect([$candidate['community']['religion'] ?? null, $candidate['community']['caste'] ?? null])->filter()->implode(' / ') ?: 'Not available' }}</dd></div>
                    </dl>

                    @auth
                        @if (auth()->user()?->matrimonyProfile)
                            <form method="POST" action="{{ $candidate['request_route'] }}" class="mt-4 space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                                @csrf
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Message</label>
                                <textarea name="message" rows="2" maxlength="2000" class="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" placeholder="Short platform request note">{{ old('message') }}</textarea>
                                <button type="submit" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Request through platform</button>
                            </form>
                        @else
                            <p class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                                Create your member profile before sending a platform request.
                            </p>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="mt-4 inline-flex rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Login to request through platform</a>
                    @endauth
                </article>
            @empty
                <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-600 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 md:col-span-2">
                    No public represented profiles are currently listed for this Suchak.
                </div>
            @endforelse
        </div>
    </section>
</div>
@endsection
