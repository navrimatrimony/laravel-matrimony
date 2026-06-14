@extends('layouts.app')

@section('content')
@php
    $existingProfileMatch = session('suchak_existing_profile_match');
@endphp

<div class="mx-auto max-w-4xl px-4 py-8">
    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $suchakAccount->office_name ?: $suchakAccount->suchak_name }}
                </p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Manual candidate profile</h1>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Candidate चा basic record तयार करा. पुढचा screen existing centralized profile form आहे.
                </p>
            </div>
            <a href="{{ route('suchak.intakes.create') }}" class="inline-flex w-fit rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                Use upload / paste instead
            </a>
        </div>

        @if ($errors->any())
            <div class="mt-6 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">
                <p class="font-semibold">Please fix the following:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($existingProfileMatch)
            <div class="mt-6 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                <p class="font-semibold">Existing profile found for this mobile number.</p>
                <p class="mt-2 leading-6">
                    Mobile {{ $existingProfileMatch['mobile_mask'] ?? 'number' }} already belongs to an existing candidate profile. A duplicate profile will not be created.
                </p>
                <div class="mt-3 grid gap-2 md:grid-cols-3">
                    <div class="rounded-md bg-white/70 p-3 dark:bg-gray-900/50">
                        <p class="font-semibold">Recommended</p>
                        <p class="mt-1 text-xs leading-5">Use the existing profile and request candidate consent.</p>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-gray-900/50">
                        <p class="font-semibold">Wrong number?</p>
                        <p class="mt-1 text-xs leading-5">Change the mobile number and submit again.</p>
                    </div>
                    <div class="rounded-md bg-white/70 p-3 dark:bg-gray-900/50">
                        <p class="font-semibold">Privacy safe</p>
                        <p class="mt-1 text-xs leading-5">Candidate details stay hidden until consent is completed.</p>
                    </div>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('suchak.manual-profiles.store') }}" class="mt-6 grid gap-5 md:grid-cols-2">
            @csrf

            <div>
                <label for="candidate_name" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Candidate full name</label>
                <input id="candidate_name" name="candidate_name" value="{{ old('candidate_name') }}" required maxlength="255" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            </div>

            <div>
                <label for="candidate_gender" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Candidate gender</label>
                <select id="candidate_gender" name="candidate_gender" required class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Select gender</option>
                    @foreach ($genders as $gender)
                        <option value="{{ $gender->key }}" @selected(old('candidate_gender') === $gender->key)>{{ $gender->label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="candidate_mobile" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Candidate mobile</label>
                <input id="candidate_mobile" name="candidate_mobile" value="{{ old('candidate_mobile') }}" inputmode="numeric" maxlength="32" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Optional. If already registered, use the existing profile after candidate consent.</p>
            </div>

            <div>
                <label for="candidate_email" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Candidate email</label>
                <input id="candidate_email" name="candidate_email" value="{{ old('candidate_email') }}" type="email" maxlength="255" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Optional.</p>
            </div>

            <div class="md:col-span-2">
                <label for="registering_for" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Who is providing this profile?</label>
                <select id="registering_for" name="registering_for" required class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    @foreach ($registeringForOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('registering_for', 'parent_guardian') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100 md:col-span-2">
                Save केल्यानंतर existing profile wizard उघडेल. पुढील सर्व biodata fields त्याच centralized engine मधून save होतील.
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-gray-200 pt-5 dark:border-gray-700 md:col-span-2">
                <a href="{{ route('suchak.dashboard', ['dashboard_tab' => 'profiles']) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</a>
                @if ($existingProfileMatch)
                    <button type="submit" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Check again</button>
                    <button type="submit" name="use_existing_profile" value="1" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Use existing profile and request consent</button>
                @else
                    <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Continue to profile form</button>
                @endif
            </div>
        </form>
    </section>
</div>
@endsection
