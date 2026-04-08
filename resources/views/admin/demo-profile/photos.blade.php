@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-1">Showcase Photos</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Profile: <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $profile->full_name ?? ('Profile #'.$profile->id) }}</span>
                <span class="ml-2 text-xs text-gray-400">(#{{ $profile->id }})</span>
            </p>
        </div>
        <a href="{{ route('admin.demo-profile.bulk-create') }}" class="shrink-0 text-sm font-semibold text-indigo-600 hover:text-indigo-700">← Back</a>
    </div>

    @if (session('success'))
        <div class="mt-4 rounded border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mt-4 rounded border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mt-4 rounded border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div>
            <h2 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-2">Upload photos</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                Slots: {{ $currentPhotoCount }} / {{ $photoMaxPerProfile }} (remaining: {{ $photoSlotsRemaining }})
                @if ($photoApprovalRequired)
                    • Approval required (uploaded photos stay pending until approved)
                @else
                    • Approval not required (uploaded photos are approved)
                @endif
            </p>

            <form method="POST" action="{{ route('admin.demo-profile.photos.store', $profile->id) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Primary photo (required)</label>
                    <input type="file" name="profile_photo" accept="image/*" required class="block w-full text-sm text-gray-700 dark:text-gray-200" {{ $photoLimitReached ? 'disabled' : '' }}>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Additional photos (optional)</label>
                    <input type="file" name="profile_photos[]" accept="image/*" multiple class="block w-full text-sm text-gray-700 dark:text-gray-200" {{ $photoLimitReached ? 'disabled' : '' }}>
                </div>

                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" {{ $photoLimitReached ? 'disabled' : '' }}>
                    Upload
                </button>
            </form>
        </div>

        <div>
            <h2 class="text-sm font-bold text-gray-800 dark:text-gray-100 mb-2">Current photos</h2>
            <div class="grid grid-cols-3 gap-3">
                @forelse ($galleryPhotos as $p)
                    <div class="rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-2">
                        <img src="{{ asset('uploads/matrimony_photos/'.$p->file_path) }}" alt="" class="h-24 w-full rounded object-cover border border-gray-200 dark:border-gray-700" />
                        <div class="mt-2 flex items-center justify-between gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <span class="font-semibold">{{ $p->is_primary ? 'Primary' : 'Photo' }}</span>
                            <span class="rounded-full px-2 py-0.5 bg-gray-100 dark:bg-gray-800">
                                {{ $p->approved_status }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">No photos yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

