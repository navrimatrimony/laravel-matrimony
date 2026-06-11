@extends('layouts.app')

@php
    $label = fn (string $value) => ucwords(str_replace('_', ' ', $value));
    $certificate = $academy['latest_certificate'];
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ $suchakAccount->suchak_name }}</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Training Academy</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Review platform-safe operating modules and reuse approved message templates.
            </p>
        </div>
        <a href="{{ route('suchak.dashboard') }}" class="inline-flex justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">
            Dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    <section class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Internal Certificate</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $academy['completed_required_count'] }} of {{ $academy['required_module_count'] }} required modules completed.
                </p>
            </div>
            @if ($certificate)
                <span class="inline-flex w-fit rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-100">
                    Internal only: {{ $certificate->certificate_code }}
                </span>
            @else
                <span class="inline-flex w-fit rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold uppercase text-amber-700 dark:bg-amber-950/50 dark:text-amber-100">
                    Certificate pending
                </span>
            @endif
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Training Modules</h2>
            <div class="mt-4 space-y-3">
                @forelse ($academy['modules'] as $module)
                    @php $completion = $academy['completions']->get($module->id); @endphp
                    <article class="rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $module->module_title }}</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ $label($module->module_category) }}</p>
                            </div>
                            <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $completion ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-100' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">
                                {{ $completion ? 'Completed' : 'Pending' }}
                            </span>
                        </div>
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $module->summary }}</p>
                        <p class="mt-3 text-sm text-gray-700 dark:text-gray-200">{{ $module->content_outline }}</p>
                    </article>
                @empty
                    <p class="text-sm text-gray-500">No active training modules are available yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Message Template Library</h2>
            <div class="mt-4 space-y-4">
                @forelse ($academy['templates'] as $template)
                    <article class="rounded-md border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $template->template_title }}</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ $label($template->template_category) }} / {{ $label($template->template_channel) }}</p>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-100">Policy safe</span>
                        </div>
                        <form method="POST" action="{{ route('suchak.training-academy.message-templates.use', $template) }}" class="mt-3 space-y-3">
                            @csrf
                            <select name="usage_context" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                                @foreach ($usageContexts as $context)
                                    <option value="{{ $context }}">{{ $label($context) }}</option>
                                @endforeach
                            </select>
                            <textarea name="rendered_body" rows="5" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">{{ old('rendered_body', $template->body_text) }}</textarea>
                            @if ($template->usage_guidance)
                                <p class="text-xs text-gray-500">{{ $template->usage_guidance }}</p>
                            @endif
                            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Record template use</button>
                        </form>
                    </article>
                @empty
                    <p class="text-sm text-gray-500">No active message templates are available yet.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Template Usage</h2>
        <div class="mt-4 space-y-3">
            @forelse ($academy['recent_template_usages'] as $usage)
                <article class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $usage->messageTemplate?->template_title }}</div>
                    <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $label($usage->usage_context) }} - {{ $usage->used_at?->format('Y-m-d H:i') }}</div>
                    <p class="mt-2 text-gray-700 dark:text-gray-200">{{ $usage->rendered_body }}</p>
                </article>
            @empty
                <p class="text-sm text-gray-500">No template usage recorded yet.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
