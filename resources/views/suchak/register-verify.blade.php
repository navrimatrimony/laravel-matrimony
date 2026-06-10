@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8">
    <div class="mb-6">
        <a href="{{ route('suchak.home') }}" class="text-sm font-semibold text-red-700 hover:underline dark:text-red-300">Back to Suchak Centre</a>
        <h1 class="mt-2 text-3xl font-bold text-gray-900 dark:text-gray-100">Mobile OTP Verification</h1>
        <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ $suchakAccount->suchak_name }} यांचा Suchak registration request तयार झाला आहे. Admin review आधी mobile OTP verify करा.
        </p>
    </div>

    @if (session('status') || session('success') || session('error'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
            {{ session('status') ?: session('success') ?: session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            <p class="font-semibold">OTP तपासा:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($otpDisplay))
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-5 text-amber-900 shadow-sm dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <p class="text-sm font-semibold">Testing OTP</p>
            <p class="mt-2 font-mono text-3xl font-bold tracking-widest">{{ $otpDisplay }}</p>
            <p class="mt-2 text-xs">Local/dev mode मध्ये हा OTP इथे दाखवला जातो.</p>
        </div>
    @endif

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <dl class="mb-5 grid gap-4 text-sm md:grid-cols-2">
            <div>
                <dt class="font-semibold text-gray-500 dark:text-gray-400">Suchak</dt>
                <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $suchakAccount->suchak_name }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-500 dark:text-gray-400">Mobile</dt>
                <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $suchakAccount->mobile_number }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-500 dark:text-gray-400">Verification</dt>
                <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ ucfirst($suchakAccount->verification_status) }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-500 dark:text-gray-400">Admin status</dt>
                <dd class="mt-1 text-gray-900 dark:text-gray-100">Waiting for review</dd>
            </div>
        </dl>

        <form id="suchak-otp-verify-form" method="POST" action="{{ route('suchak.register.verify.submit') }}" class="space-y-4">
            @csrf
            <div>
                <label for="otp" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Enter 6 digit OTP</label>
                <input id="otp" name="otp" required maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" class="mt-2 w-full rounded-md border-gray-300 font-mono text-lg tracking-widest dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" placeholder="000000">
            </div>
        </form>

        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <form method="POST" action="{{ route('suchak.register.otp.resend') }}">
                @csrf
                <button type="submit" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    Send new OTP
                </button>
            </form>
            <button type="submit" form="suchak-otp-verify-form" class="rounded-md bg-red-600 px-5 py-2 text-sm font-semibold text-white hover:bg-red-700">
                Verify OTP
            </button>
        </div>
    </section>
</div>
@endsection
