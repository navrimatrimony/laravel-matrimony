@extends('layouts.admin')

@section('content')
<div class="max-w-2xl bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">Restore Verification Tag</h1>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mb-6 p-4 border rounded bg-gray-50 dark:bg-gray-700/40">
        <p class="text-sm text-gray-600 dark:text-gray-300">Tag name</p>
        <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $tag->name }}</p>
    </div>

    <form method="POST" action="{{ route('admin.verification-tags.restore', $tag->id) }}">
        @csrf
        <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">Reason <span class="text-red-600">*</span></label>
        <textarea
            name="reason"
            rows="3"
            required
            minlength="1"
            class="w-full border border-gray-300 dark:border-gray-600 rounded-md px-3 py-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
        >{{ old('reason') }}</textarea>

        <div class="mt-4 flex gap-3">
            <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md">Confirm Restore</button>
            <a href="{{ route('admin.verification-tags.index') }}" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md">Cancel</a>
        </div>
    </form>
</div>
@endsection
