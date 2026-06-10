@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8">
    <div class="mb-6">
        <p class="text-sm font-semibold text-red-700 dark:text-red-300">Suchak Centre</p>
        <h1 class="mt-1 text-3xl font-bold text-gray-900 dark:text-gray-100">सूचक कामासाठी सोपे पान</h1>
        <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
            नवीन सूचक नोंदणी, OTP, admin approval, customer biodata entry, masked search आणि dashboard या सर्व links इथे आहेत.
        </p>
    </div>

    @if (session('status') || session('info') || session('error'))
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ session('status') ?: session('info') ?: session('error') }}
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('suchak.register.info') }}" class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-red-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-red-700">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Step 1</div>
            <h2 class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">नवीन सूचक नोंदणी</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">स्वतःचे नाव, mobile, office आणि password भरून Suchak account request करा.</p>
        </a>

        <a href="{{ auth()->check() ? route('suchak.register.verify') : route('login') }}" class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-red-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-red-700">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Step 2</div>
            <h2 class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">Mobile OTP Verify</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Registration नंतर mobile OTP verify करा. Local testing मध्ये OTP page वर दिसतो.</p>
        </a>

        <a href="{{ auth()->check() ? route('suchak.register.status') : route('login') }}" class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-red-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-red-700">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Step 3</div>
            <h2 class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">Request Status</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">OTP, KYC documents आणि admin approval pending आहे का ते इथे बघा.</p>
        </a>

        @if (auth()->user()?->is_admin)
            <a href="{{ route('admin.suchak.accounts.index') }}" class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-red-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-red-700">
                <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Admin</div>
                <h2 class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">Admin Approval</h2>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Admin pending Suchak request review करून approve किंवा reject करतो.</p>
            </a>
        @endif

        <a href="{{ route('suchak.dashboard') }}" class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-red-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-red-700">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Work</div>
            <h2 class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">Suchak Dashboard</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Approval नंतर customer entry, QR, collaboration आणि activity इथून चालेल.</p>
        </a>

        <a href="{{ route('suchak.intakes.create') }}" class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-red-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-red-700">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Customer Entry</div>
            <h2 class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">Customer Biodata Entry</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Verified Suchak customer चा biodata paste किंवा upload करू शकतो.</p>
        </a>

        <a href="{{ route('suchak.search.index') }}" class="block rounded-lg border border-gray-200 bg-white p-5 shadow-sm hover:border-red-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-red-700">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Search</div>
            <h2 class="mt-2 text-lg font-bold text-gray-900 dark:text-gray-100">Masked Search</h2>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Private contact leak न होता इतर suitable profiles masked पद्धतीने शोधा.</p>
        </a>
    </div>

    <section class="mt-8 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">सरळ process</h2>
        <ol class="mt-4 grid gap-3 text-sm text-gray-700 dark:text-gray-300 md:grid-cols-4">
            <li class="rounded-md bg-gray-50 p-3 dark:bg-gray-900">1. नोंदणी करा.</li>
            <li class="rounded-md bg-gray-50 p-3 dark:bg-gray-900">2. Mobile OTP verify करा.</li>
            <li class="rounded-md bg-gray-50 p-3 dark:bg-gray-900">3. Admin approval होऊ द्या.</li>
            <li class="rounded-md bg-gray-50 p-3 dark:bg-gray-900">4. Customer biodata entry सुरू करा.</li>
        </ol>
    </section>
</div>
@endsection
