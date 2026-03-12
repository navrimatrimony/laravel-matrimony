@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Homepage Images</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Upload or change images for each section on the front page. Supported: JPG, PNG, GIF (max 5MB).</p>

    <div class="space-y-8">
        @foreach($sections as $section)
            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 flex flex-col sm:flex-row gap-4 items-start sm:items-center">
                <div class="flex-1 min-w-0">
                    <h2 class="font-semibold text-gray-800 dark:text-gray-100">{{ $section['label'] }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Section key: <code>{{ $section['key'] }}</code></p>
                    @if($section['current_url'])
                        <div class="mt-2">
                            <img src="{{ $section['current_url'] }}" alt="{{ $section['label'] }}" class="max-h-24 rounded border border-gray-200 dark:border-gray-600 object-cover" onerror="this.parentElement.innerHTML='<span class=\'text-gray-400 text-sm\'>Image not found</span>'">
                        </div>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">No image set (default/placeholder used on front page).</p>
                    @endif
                </div>
                <div class="flex flex-col gap-2 sm:flex-row shrink-0">
                    <form action="{{ route('admin.homepage-images.store') }}" method="POST" enctype="multipart/form-data" class="flex flex-col gap-2">
                        @csrf
                        <input type="hidden" name="section_key" value="{{ $section['key'] }}">
                        <input type="file" name="image" accept="image/jpeg,image/png,image/gif" class="text-sm text-gray-600 dark:text-gray-300 file:mr-2 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-sm file:font-medium file:bg-indigo-50 file:text-indigo-700 dark:file:bg-gray-600 dark:file:text-gray-200">
                        <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700">Upload</button>
                        @error('image')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </form>
                    @if($section['current_path'])
                        <form action="{{ route('admin.homepage-images.clear') }}" method="POST" onsubmit="return confirm('Remove this image and use default/placeholder?');">
                            @csrf
                            <input type="hidden" name="section_key" value="{{ $section['key'] }}">
                            <button type="submit" class="px-3 py-1.5 border border-gray-300 dark:border-gray-500 text-gray-700 dark:text-gray-300 text-sm rounded hover:bg-gray-100 dark:hover:bg-gray-700">Clear</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
        <a href="{{ url('/') }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">View front page →</a>
    </p>
</div>
@endsection
