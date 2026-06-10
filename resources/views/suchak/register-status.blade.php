@extends('layouts.app')

@section('content')
@php
    $mobileVerified = auth()->user()?->mobile_verified_at !== null;
    $statusTone = match ($suchakAccount->verification_status) {
        \App\Models\SuchakAccount::VERIFICATION_VERIFIED => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
        \App\Models\SuchakAccount::VERIFICATION_REJECTED,
        \App\Models\SuchakAccount::VERIFICATION_SUSPENDED => 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100',
        default => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    };
@endphp

<div class="mx-auto max-w-5xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <a href="{{ route('suchak.home') }}" class="text-sm font-semibold text-red-700 hover:underline dark:text-red-300">Back to Suchak Centre</a>
            <h1 class="mt-2 text-3xl font-bold text-gray-900 dark:text-gray-100">Suchak Request Status</h1>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                तुमची Suchak registration request, OTP आणि KYC document review status इथे दिसेल.
            </p>
        </div>

        @if ($suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_VERIFIED)
            <a href="{{ route('suchak.dashboard') }}" class="inline-flex items-center justify-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                Open Dashboard
            </a>
        @endif
    </div>

    @if (session('success') || session('status') || session('info') || session('error'))
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ session('success') ?: session('status') ?: session('info') ?: session('error') }}
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $suchakAccount->suchak_name }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {{ $suchakAccount->office_name ?: 'Individual Suchak' }}
                    </p>
                </div>
                <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold {{ $statusTone }}">
                    {{ ucfirst($suchakAccount->verification_status) }}
                </span>
            </div>

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <dt class="font-semibold text-gray-700 dark:text-gray-300">Mobile OTP</dt>
                    <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $mobileVerified ? 'Verified' : 'Pending' }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <dt class="font-semibold text-gray-700 dark:text-gray-300">Business type</dt>
                    <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ ucfirst($suchakAccount->business_type) }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <dt class="font-semibold text-gray-700 dark:text-gray-300">Public status</dt>
                    <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ ucfirst($suchakAccount->public_status) }}</dd>
                </div>
                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-900">
                    <dt class="font-semibold text-gray-700 dark:text-gray-300">Submitted</dt>
                    <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ optional($suchakAccount->created_at)->format('d M Y, h:i A') }}</dd>
                </div>
            </dl>

            <div class="mt-6 rounded-md border border-gray-200 p-4 text-sm leading-6 text-gray-700 dark:border-gray-700 dark:text-gray-300">
                @if (! $mobileVerified)
                    <p class="font-semibold text-gray-900 dark:text-gray-100">Mobile OTP verification pending.</p>
                    <p class="mt-1">OTP verify केल्यावर admin तुमचे KYC documents review करू शकतील.</p>
                    <a href="{{ route('suchak.register.verify') }}" class="mt-3 inline-flex rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                        Verify OTP
                    </a>
                @elseif ($suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_VERIFIED)
                    <p class="font-semibold text-gray-900 dark:text-gray-100">Admin approval complete.</p>
                    <p class="mt-1">आता Suchak dashboard मधून customer biodata entry आणि Suchak work सुरू करता येईल.</p>
                @elseif ($suchakAccount->verification_status === \App\Models\SuchakAccount::VERIFICATION_REJECTED)
                    <p class="font-semibold text-gray-900 dark:text-gray-100">Request rejected.</p>
                    <p class="mt-1">Admin remarks तपासा आणि platform admin शी संपर्क करा.</p>
                @else
                    <p class="font-semibold text-gray-900 dark:text-gray-100">Admin approval pending.</p>
                    <p class="mt-1">OTP verify झाल्यावर admin KYC documents आणि account details review करेल.</p>
                @endif
            </div>
        </section>

        <aside class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">पुढचे steps</h2>
            <ol class="mt-4 space-y-3 text-sm leading-6 text-gray-700 dark:text-gray-300">
                <li>1. Mobile OTP verified असणे आवश्यक.</li>
                <li>2. Identity आणि office proof admin review मध्ये जातील.</li>
                <li>3. Approval नंतर dashboard मधून customer entry सुरू होईल.</li>
            </ol>
        </aside>
    </div>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">KYC Documents</h2>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $verificationRecords->count() }} uploaded</span>
        </div>

        @if ($verificationRecords->isEmpty())
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">KYC document record अजून तयार झालेला नाही.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Type</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Upload</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Admin status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($verificationRecords as $record)
                            <tr>
                                <td class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ ucfirst($record->verification_type) }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $record->document_path ? 'Uploaded' : 'Not uploaded' }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ ucfirst($record->admin_status) }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $record->remarks ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
