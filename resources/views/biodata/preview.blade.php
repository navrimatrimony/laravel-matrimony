@extends('layouts.app')

@section('content')
@php
    $allowed = (bool) ($exportState['allowed'] ?? false);
@endphp

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-red-700 dark:text-red-300">{{ $template['label'] }}</p>
            <h1 class="text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100">{{ __('profile.biodata_export_title') }}</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('matrimony.profile.biodata.index') }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Templates</a>
            @if ($allowed)
                <a href="{{ route('matrimony.profile.biodata.pdf', $template['key']) }}" class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-700">PDF</a>
                <a href="{{ route('matrimony.profile.biodata.jpg', $template['key']) }}" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-black dark:bg-gray-100 dark:text-gray-900">JPG</a>
                <a href="{{ route('matrimony.profile.biodata.print', $template['key']) }}" target="_blank" class="rounded-md border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-200 dark:hover:bg-red-950/40">Print</a>
            @endif
        </div>
    </div>

    <div class="overflow-auto rounded-lg border border-gray-200 bg-gray-100 p-4 shadow-inner dark:border-gray-700 dark:bg-gray-950">
        @include('biodata.templates.a4', ['payload' => $payload, 'template' => $template, 'pdfMode' => false])
    </div>
</div>
@endsection
