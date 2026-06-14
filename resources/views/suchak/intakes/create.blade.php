@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8">
    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="mb-6">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $suchakAccount->office_name ?: $suchakAccount->suchak_name }}
            </p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Create Suchak Intake Source Link</h1>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                Upload or paste biodata through the existing intake engine. This creates source tracking only.
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('suchak.manual-profiles.create') }}" class="rounded-md border border-indigo-300 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-200 dark:hover:bg-indigo-950/40">
                    Fill profile manually
                </a>
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200">
                <p class="font-semibold">Please fix the following:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('suchak.intakes.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <div>
                <label for="raw_text" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Paste biodata text</label>
                <textarea id="raw_text" name="raw_text" rows="8" class="mt-2 w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" placeholder="Paste biodata text here">{{ old('raw_text') }}</textarea>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Use either pasted text or file upload.</p>
            </div>

            <div class="flex items-center gap-4">
                <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
                <span class="text-xs font-semibold uppercase text-gray-400">or</span>
                <span class="h-px flex-1 bg-gray-200 dark:bg-gray-700"></span>
            </div>

            <div>
                <label for="file" class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Upload biodata file</label>
                <input id="file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.txt" class="mt-2 block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-indigo-700 dark:text-gray-300">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">PDF, image, or TXT. Existing intake limits still apply.</p>
            </div>

            <div class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                This does not create or update a canonical candidate profile. Existing intake review and governed apply remain required.
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                <a href="{{ route('suchak.dashboard') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</a>
                <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Create source link</button>
            </div>
        </form>
    </section>
</div>
@endsection
