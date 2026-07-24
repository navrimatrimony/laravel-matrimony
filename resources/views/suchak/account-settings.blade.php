@extends('layouts.app')

@php
    $suchakText = \App\Support\Suchak\SuchakLocalizedText::class;
    $localizedText = \App\Support\LocalizedText::class;
@endphp

@section('content')
<div class="mx-auto max-w-5xl px-4 py-6">
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-red-700 dark:text-red-300">Suchak account</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Account contacts</h1>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                Primary WhatsApp number login आणि OTP साठी वापरला जातो. Extra office/helper numbers इथे add करा.
            </p>
        </div>
        <a href="{{ route('suchak.dashboard') }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
            Dashboard
        </a>
    </div>

    @if (session('success') || session('error') || session('info'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
            {{ session('success') ?: session('error') ?: session('info') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1fr_1.2fr]">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Primary number</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div>
                    <dt class="font-semibold text-gray-600 dark:text-gray-300">WhatsApp / Login</dt>
                    <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $suchakAccount->whatsapp_number ?: $suchakAccount->mobile_number }}</dd>
                </div>
                <div>
                    <dt class="font-semibold text-gray-600 dark:text-gray-300">OTP status</dt>
                    <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $suchakAccount->user?->mobile_verified_at ? 'Verified' : 'Not verified' }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Add extra number</h2>
            <form method="POST" action="{{ route('suchak.account-settings.contact-numbers.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                @csrf
                <div>
                    <label for="phone_number" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Mobile number</label>
                    <input id="phone_number" name="phone_number" value="{{ old('phone_number') }}" required maxlength="32" inputmode="numeric" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label for="label" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Label</label>
                    <input id="label" name="label" value="{{ old('label') }}" maxlength="80" placeholder="Office, assistant, branch" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label for="label_mr" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Label Marathi</label>
                    <input id="label_mr" name="label_mr" value="{{ old('label_mr') }}" maxlength="80" placeholder="Office, assistant, branch Marathi" class="mt-1 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-100 sm:col-span-2">
                    <input type="hidden" name="is_whatsapp" value="0">
                    <input type="checkbox" name="is_whatsapp" value="1" class="rounded border-gray-300 text-red-600">
                    This number is also available on WhatsApp
                </label>
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Add number</button>
                </div>
            </form>
        </section>
    </div>

    <section class="mt-5 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Extra numbers</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Number</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Label</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">WhatsApp</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($contactNumbers as $number)
                        <tr>
                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $number->phone_number }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $localizedText::column($number, 'label') ?: '-' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $number->is_whatsapp ? $suchakText::label('yes') : $suchakText::label('no') }}</td>
                            <td class="px-3 py-2">
                                <form method="POST" action="{{ route('suchak.account-settings.contact-numbers.destroy', $number) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-sm font-semibold text-red-700 hover:underline dark:text-red-300">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-5 text-center text-gray-500 dark:text-gray-400">No extra numbers added yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
