@extends('layouts.admin')

@section('content')
<div class="max-w-4xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('matching_engine.nav_fields') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('matching_engine.fields_heading') }} — {{ __('matching_engine.field_weight_total') }}: <span class="font-semibold text-rose-600">{{ $sumWeights }}</span> / 100</p>
    </div>
    @if (! $canEdit)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('matching_engine.read_only') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ route('admin.matching-engine.fields.save') }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-6">
        @csrf
        @foreach ($fields as $f)
            <div class="border-b border-gray-100 dark:border-gray-700 pb-6 last:border-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
                    <div>
                        <span class="font-semibold text-gray-900 dark:text-white">{{ $f->label }}</span>
                        <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ $f->category }}</span>
                        <span class="ml-1 text-xs text-gray-400 font-mono">{{ $f->field_key }}</span>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="active[{{ $f->field_key }}]" value="1" class="rounded border-gray-300" @checked(old('active.'.$f->field_key, $f->is_active)) @disabled(! $canEdit) />
                        Active
                    </label>
                </div>
                <div class="flex items-center gap-4">
                    <input type="range" name="weights[{{ $f->field_key }}]" min="0" max="{{ $f->max_weight }}" value="{{ old('weights.'.$f->field_key, $f->weight) }}"
                        class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-rose-600" @disabled(! $canEdit) />
                    <span class="w-10 text-right font-mono text-sm text-gray-700 dark:text-gray-200">{{ old('weights.'.$f->field_key, $f->weight) }}</span>
                </div>
            </div>
        @endforeach
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Note (audit)</label>
            <input type="text" name="note" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm" @disabled(! $canEdit) />
        </div>
        @if ($canEdit)
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-sm font-medium">Save fields</button>
        @endif
    </form>
</div>
<script>
document.querySelectorAll('input[type=range][name^="weights"]').forEach(function (el) {
    const out = el.nextElementSibling;
    el.addEventListener('input', function () { if (out) out.textContent = el.value; });
});
</script>
@endsection
