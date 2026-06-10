@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-8">
    <section class="rounded-lg border border-red-200 bg-white p-6 shadow-sm dark:border-red-900 dark:bg-gray-800">
        <p class="text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-300">Secure Suchak QR</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">QR link unavailable</h1>
        <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ $message }}
        </p>
    </section>
</div>
@endsection
