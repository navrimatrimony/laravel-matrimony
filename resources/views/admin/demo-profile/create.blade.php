@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Create Demo Profile</h1>
    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Single demo profile. Mandatory fields are auto-filled.</p>
    @if ($errors->any())
        <ul class="text-red-600 text-sm mb-4 space-y-1">
            @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
        </ul>
    @endif
    <div class="mb-6 p-4 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-sky-800 dark:text-sky-200 text-sm">
        <p class="font-semibold mb-2">Auto-filled fields</p>
        <ul class="list-disc pl-5 space-y-0.5">
            <li>Gender</li>
            <li>Date of birth</li>
            <li>Marital status</li>
            <li>Education</li>
            <li>Location</li>
            <li>Profile photo (placeholder)</li>
        </ul>
    </div>
    <div class="mb-6">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Preview — profile photo</p>
        <img src="{{ asset('uploads/matrimony_photos/demo_placeholder.png') }}" alt="Demo" onerror="this.src='{{ asset('images/default-profile.png') }}';" class="w-24 h-24 rounded-full object-cover border-2 border-gray-200 dark:border-gray-600" />
    </div>
    <form method="POST" action="{{ route('admin.demo-profile.store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gender (optional)</label>
            <select name="gender" class="border border-gray-300 dark:border-gray-600 rounded px-3 py-2 w-full max-w-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100">
                <option value="">—</option>
                <option value="male" {{ old('gender') === 'male' ? 'selected' : '' }}>Male</option>
                <option value="female" {{ old('gender') === 'female' ? 'selected' : '' }}>Female</option>
            </select>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">If not selected, a random gender is assigned.</p>
        </div>
        <div>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="demo_profile" value="1" {{ old('demo_profile') ? 'checked' : '' }} required>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Create as Demo Profile</span>
            </label>
        </div>
        <div class="flex gap-3">
            <button type="submit" style="background-color: #059669; color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Create</button>
            <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
