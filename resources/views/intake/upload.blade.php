@extends('layouts.app')

@section('content')
<div class="min-h-[80vh] bg-gradient-to-b from-gray-50 to-white dark:from-gray-900 dark:to-gray-800">
    <div class="max-w-2xl mx-auto py-8 px-4 sm:px-6">
        {{-- Back link --}}
        <p class="mb-6">
            <a href="{{ route('intake.index') }}" class="inline-flex items-center text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                {{ __('intake.my_biodata_uploads') }}
            </a>
        </p>

        {{-- Header --}}
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 mb-4">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 tracking-tight">Upload Biodata</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400">Paste text or upload a PDF/image. We’ll extract and structure it for your profile.</p>
        </div>

        {{-- Alerts --}}
        @if (session('success'))
            <div class="mb-6 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 text-sm font-medium">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                <p class="text-sm font-medium text-red-800 dark:text-red-200 mb-2">Please fix the following:</p>
                <ul class="list-disc list-inside text-sm text-red-700 dark:text-red-300 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Form card --}}
        <div
            x-data="{
                drag: false,
                fileLabel: null,
                hasPaste: {{ strlen(old('raw_text', '')) > 0 ? 'true' : 'false' }},
                hasFile: false,
                init() {
                    const fileInput = this.$refs.fileInput;
                    if (fileInput && fileInput.files.length) {
                        this.hasFile = true;
                        this.fileLabel = fileInput.files[0].name;
                    }
                },
                isImagePresetVisible() {
                    if (!this.hasFile || !this.$refs.fileInput || !this.$refs.fileInput.files || !this.$refs.fileInput.files.length) return false;
                    const n = (this.fileLabel || '').toLowerCase();
                    return /\.(jpe?g|png|gif|webp|bmp)$/.test(n);
                }
            }"
            @dragover.prevent="drag = true"
            @dragleave.prevent="drag = false"
            @drop.prevent="drag = false; $refs.fileInput.files = $event.dataTransfer.files; hasFile = $event.dataTransfer.files.length; if ($event.dataTransfer.files.length) fileLabel = $event.dataTransfer.files[0].name"
            class="relative overflow-hidden rounded-2xl bg-white dark:bg-gray-800 shadow-xl shadow-gray-200/50 dark:shadow-none ring-1 ring-gray-200/80 dark:ring-gray-700"
        >
            <div class="absolute inset-0 bg-gradient-to-br from-red-500/5 to-transparent dark:from-red-500/10 pointer-events-none rounded-2xl"></div>
            <form method="POST" action="{{ route('intake.store') }}" enctype="multipart/form-data" class="relative px-6 py-8 sm:px-8 sm:py-10">
                @csrf

                {{-- Paste text --}}
                <div class="mb-8">
                    <label for="raw_text" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                        Paste biodata text
                    </label>
                    <textarea
                        name="raw_text"
                        id="raw_text"
                        rows="6"
                        placeholder="Paste your biodata text here (name, details, family, preferences…)"
                        class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:ring-2 focus:ring-red-500 focus:border-red-500 dark:focus:ring-red-500 dark:focus:border-red-500 px-4 py-3 text-sm transition resize-y min-h-[120px]"
                        x-on:input="hasPaste = $event.target.value.length > 0"
                    >{{ old('raw_text') }}</textarea>
                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Paste from Word, WhatsApp, or any text source.</p>
                </div>

                {{-- Divider --}}
                <div class="flex items-center gap-4 mb-8">
                    <span class="flex-1 h-px bg-gray-200 dark:bg-gray-600"></span>
                    <span class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider">or</span>
                    <span class="flex-1 h-px bg-gray-200 dark:bg-gray-600"></span>
                </div>

                {{-- File upload --}}
                <div class="mb-8">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                        Upload file
                    </label>
                    <label
                        for="file"
                        class="flex flex-col items-center justify-center w-full min-h-[140px] rounded-xl border-2 border-dashed cursor-pointer transition"
                        :class="drag
                            ? 'border-red-500 bg-red-50/50 dark:bg-red-900/20'
                            : 'border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700/30'"
                    >
                        <input
                            type="file"
                            name="file"
                            id="file"
                            accept=".pdf,.jpg,.jpeg,.png,.txt"
                            class="hidden"
                            x-ref="fileInput"
                            x-on:change="hasFile = $event.target.files.length; fileLabel = $event.target.files.length ? $event.target.files[0].name : null"
                        >
                        <template x-if="!fileLabel">
                            <div class="text-center px-4 py-6">
                                <svg class="mx-auto h-10 w-10 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                </svg>
                                <p class="mt-2 text-sm font-medium text-gray-600 dark:text-gray-400">Click to choose or drag and drop</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">PDF, JPG, PNG or TXT (max 20 MB)</p>
                            </div>
                        </template>
                        <template x-if="fileLabel">
                            <div class="flex items-center gap-3 px-4 py-6 text-left w-full">
                                <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="fileLabel"></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Click or drop again to change</p>
                                </div>
                            </div>
                        </template>
                    </label>
                </div>

                {{-- OCR preprocessing preset: images only (hidden/disabled for PDF/TXT or no file) --}}
                <div
                    class="mb-8 rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-50/80 dark:bg-gray-900/30 px-4 py-3"
                    x-show="isImagePresetVisible()"
                    x-cloak
                >
                    <label for="preprocessing_preset" class="block text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1">
                        {{ __('intake.preprocessing_preset_label') }}
                    </label>
                    <select
                        name="preprocessing_preset"
                        id="preprocessing_preset"
                        class="mt-1 w-full max-w-md rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm py-2 px-3 focus:ring-2 focus:ring-red-500 focus:border-red-500"
                        x-bind:disabled="!isImagePresetVisible()"
                    >
                        <option value="auto" @selected(old('preprocessing_preset', 'auto') === 'auto')>{{ __('intake.preprocessing_preset_option_auto') }}</option>
                        <option value="clean_document" @selected(old('preprocessing_preset') === 'clean_document')>{{ __('intake.preprocessing_preset_option_clean_document') }}</option>
                        <option value="marathi_printed" @selected(old('preprocessing_preset') === 'marathi_printed')>{{ __('intake.preprocessing_preset_option_marathi_printed') }}</option>
                        <option value="noisy_scan" @selected(old('preprocessing_preset') === 'noisy_scan')>{{ __('intake.preprocessing_preset_option_noisy_scan') }}</option>
                        <option value="photo_capture" @selected(old('preprocessing_preset') === 'photo_capture')>{{ __('intake.preprocessing_preset_option_photo_capture') }}</option>
                        @if (config('app.debug'))
                            <option value="off" @selected(old('preprocessing_preset') === 'off')>{{ __('intake.preprocessing_preset_option_off_debug') }}</option>
                        @endif
                    </select>
                    <p class="mt-2 text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                        {{ __('intake.preprocessing_preset_help') }}
                    </p>
                    <div class="mt-3 rounded-lg bg-white/90 dark:bg-gray-800/80 border border-gray-200 dark:border-gray-600 px-3 py-2 text-xs text-gray-700 dark:text-gray-300 leading-relaxed">
                        {{ __('intake.preprocessing_preset_info_box') }}
                    </div>
                </div>

                {{-- Submit --}}
                <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-4 pt-2">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Use either text or file. We’ll parse and show a preview before applying.
                    </p>
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold text-sm shadow-lg shadow-red-500/25 hover:shadow-red-500/30 transition focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                        </svg>
                        {{ __('intake.upload_biodata') }}
                    </button>
                </div>
            </form>
        </div>

        {{-- Footer hint --}}
        <p class="mt-6 text-center text-xs text-gray-500 dark:text-gray-400">
            Supported: PDF (with text or scanned), JPG/PNG images, plain TXT. Large PDFs may take a moment to process.
        </p>
    </div>
</div>
@endsection
