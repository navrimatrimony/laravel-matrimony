@extends('layouts.admin')

@php
    $label = fn (string $value) => ucwords(str_replace('_', ' ', $value));
@endphp

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8">
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Suchak Training Academy</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                Admin-managed training, internal certificates, and reusable policy-safe communication templates.
            </p>
        </div>
        <a href="{{ route('admin.suchak.dashboard') }}" class="inline-flex justify-center rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-100 dark:hover:bg-gray-700">
            Admin dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">{{ session('error') }}</div>
    @endif

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Modules</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['modules']->count() }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Templates</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['templates']->count() }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Certificates</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['recent_certificates']->count() }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Template uses</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $summary['recent_template_usages']->count() }}</div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Create Training Module</h2>
            <form method="POST" action="{{ route('admin.suchak.academy.modules.store') }}" class="mt-4 space-y-3">
                @csrf
                <div class="grid gap-3 md:grid-cols-2">
                    <input name="module_key" value="{{ old('module_key') }}" placeholder="privacy_basics_v1" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <input name="module_title" value="{{ old('module_title') }}" placeholder="Privacy Basics" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <select name="module_category" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        @foreach ($moduleCategories as $category)
                            <option value="{{ $category }}">{{ $label($category) }}</option>
                        @endforeach
                    </select>
                    <input name="sort_order" type="number" min="0" max="65535" value="{{ old('sort_order', 0) }}" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" name="is_required_for_certificate" value="1" checked class="rounded border-gray-300">
                    Required for internal certificate
                </label>
                <textarea name="summary" rows="3" placeholder="Short policy-safe summary" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ old('summary') }}</textarea>
                <textarea name="content_outline" rows="4" placeholder="Training outline without contact details, direct payment handles, or success guarantees" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ old('content_outline') }}</textarea>
                <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900">Create module</button>
            </form>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Create Message Template</h2>
            <form method="POST" action="{{ route('admin.suchak.academy.message-templates.store') }}" class="mt-4 space-y-3">
                @csrf
                <div class="grid gap-3 md:grid-cols-2">
                    <input name="template_key" value="{{ old('template_key') }}" placeholder="consent_followup_v1" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <input name="template_title" value="{{ old('template_title') }}" placeholder="Consent follow-up" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <select name="template_category" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        @foreach ($templateCategories as $category)
                            <option value="{{ $category }}">{{ $label($category) }}</option>
                        @endforeach
                    </select>
                    <select name="template_channel" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        @foreach ($templateChannels as $channel)
                            <option value="{{ $channel }}">{{ $label($channel) }}</option>
                        @endforeach
                    </select>
                </div>
                <textarea name="body_text" rows="4" placeholder="Use platform links only. Keep details masked." class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ old('body_text') }}</textarea>
                <textarea name="usage_guidance" rows="3" placeholder="When this template should be used" class="w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ old('usage_guidance') }}</textarea>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Create template</button>
            </form>
        </section>
    </div>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Modules + Completion</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="text-left text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pr-4">Module</th>
                        <th class="py-2 pr-4">Category</th>
                        <th class="py-2 pr-4">Required</th>
                        <th class="py-2 pr-4">Completions</th>
                        <th class="py-2 pr-4">Record completion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($summary['modules'] as $module)
                        <tr>
                            <td class="py-3 pr-4 text-gray-900 dark:text-gray-100">
                                <div class="font-semibold">{{ $module->module_title }}</div>
                                <div class="text-xs text-gray-500">{{ $module->module_key }}</div>
                            </td>
                            <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $label($module->module_category) }}</td>
                            <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $module->is_required_for_certificate ? 'Yes' : 'No' }}</td>
                            <td class="py-3 pr-4 text-gray-600 dark:text-gray-300">{{ $module->completions_count }}</td>
                            <td class="py-3 pr-4">
                                <form method="POST" action="{{ route('admin.suchak.academy.modules.completions.store', $module) }}" class="grid gap-2 md:grid-cols-4">
                                    @csrf
                                    <select name="suchak_account_id" class="rounded-md border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        @foreach ($summary['accounts'] as $account)
                                            <option value="{{ $account->id }}">{{ $account->suchak_name }} #{{ $account->id }}</option>
                                        @endforeach
                                    </select>
                                    <input name="score_percent" type="number" min="0" max="100" placeholder="Score" class="rounded-md border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    <input name="completion_note" placeholder="Completion evidence note" class="rounded-md border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                    <button type="submit" class="rounded-md bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">Complete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-gray-500">No training modules created yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Internal Certificate Issue</h2>
        <form method="POST" action="{{ $summary['accounts']->isNotEmpty() ? route('admin.suchak.academy.accounts.certificates.issue', $summary['accounts']->first()) : '#' }}" class="mt-4 grid gap-3 md:grid-cols-4" id="certificate-form">
            @csrf
            <select class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" onchange="document.getElementById('certificate-form').action=this.options[this.selectedIndex].dataset.action">
                @foreach ($summary['accounts'] as $account)
                    <option value="{{ $account->id }}" data-action="{{ route('admin.suchak.academy.accounts.certificates.issue', $account) }}">{{ $account->suchak_name }} #{{ $account->id }}</option>
                @endforeach
            </select>
            <input name="certificate_note" class="rounded-md border-gray-300 text-sm md:col-span-2 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" placeholder="Internal certificate evidence note">
            <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900">Issue internal certificate</button>
        </form>
    </section>

    <section class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Policy-Safe Templates</h2>
            <div class="mt-4 space-y-3">
                @forelse ($summary['templates'] as $template)
                    <article class="rounded-md border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $template->template_title }}</h3>
                            <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-100">{{ $label($template->policy_status) }}</span>
                        </div>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $template->body_text }}</p>
                        <p class="mt-2 text-xs text-gray-500">{{ $label($template->template_category) }} / {{ $label($template->template_channel) }} / {{ $template->usages_count }} uses</p>
                    </article>
                @empty
                    <p class="text-sm text-gray-500">No message templates created yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Recent Internal Certificates</h2>
            <div class="mt-4 space-y-3">
                @forelse ($summary['recent_certificates'] as $certificate)
                    <article class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $certificate->certificate_code }}</div>
                        <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $certificate->suchakAccount?->suchak_name }} - {{ $label($certificate->certificate_status) }}</div>
                        <div class="mt-1 text-xs text-gray-500">Badge: {{ $label($certificate->public_badge_status) }}</div>
                    </article>
                @empty
                    <p class="text-sm text-gray-500">No internal certificates issued yet.</p>
                @endforelse
            </div>
        </div>
    </section>
</div>
@endsection
