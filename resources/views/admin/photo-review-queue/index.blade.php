@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">Photo review queue</h1>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
        Each row is <strong>one image</strong> waiting for review: the member’s <strong>primary</strong> photo (public visibility) or an extra <strong>gallery</strong> photo. Enter a reason (minimum 10 characters), then <strong>Approve</strong>, <strong>Reject</strong>, or <strong>Delete</strong>.
        Use <strong>Open profile</strong> for the full admin profile page.
    </p>

    @if ($items->isEmpty())
        <p class="text-gray-500 dark:text-gray-400 text-sm">No photos are waiting for review.</p>
    @else
        <div class="overflow-x-auto border border-gray-200 dark:border-gray-600 rounded-lg">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-left text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Profile ID</th>
                        <th class="px-4 py-3 font-semibold">Name</th>
                        <th class="px-4 py-3 font-semibold">Slot</th>
                        <th class="px-4 py-3 font-semibold">User</th>
                        <th class="px-4 py-3 font-semibold">Preview</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 font-semibold min-w-[10rem]">NudeNet scan</th>
                        <th class="px-4 py-3 font-semibold">Updated</th>
                        <th class="px-4 py-3 font-semibold min-w-[220px]">Moderation</th>
                        <th class="px-4 py-3 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                    @foreach ($items as $item)
                        @php
                            $p = $item->profile;
                            $gp = $item->profilePhoto;
                            $path = trim((string) ($p->profile_photo ?? ''));
                            $isPendingFile = \App\Services\Image\ProfilePhotoUrlService::isPendingPlaceholder($path);
                            $tmpReady = $isPendingFile && \App\Services\Image\ProfilePhotoUrlService::resolvePendingTempAbsolutePath($path) !== null;
                            $previewUrl = $gp !== null
                                ? route('admin.photo-review-queue.preview', ['profile' => $p, 'galleryPhoto' => $gp])
                                : route('admin.photo-review-queue.preview', ['profile' => $p]);
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 align-top">
                            <td class="px-4 py-3 text-gray-800 dark:text-gray-200">{{ $p->id }}</td>
                            <td class="px-4 py-3 text-gray-800 dark:text-gray-200">{{ $p->full_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                @if ($item->kind === 'primary')
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100">Primary</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-violet-100 text-violet-900 dark:bg-violet-900/40 dark:text-violet-100">Gallery #{{ $gp?->id }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $p->user?->email ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="relative h-16 w-16 shrink-0">
                                    <img
                                        src="{{ $previewUrl }}"
                                        alt=""
                                        class="h-16 w-16 rounded-lg object-cover border border-gray-200 dark:border-gray-600"
                                        loading="lazy"
                                        onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');"
                                    >
                                    <span class="hidden text-xs text-gray-500 dark:text-gray-400 leading-tight block max-w-[7rem]">No preview (file missing or still processing)</span>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if ($item->kind === 'primary' && $isPendingFile && ! $tmpReady)
                                    <span class="text-amber-700 dark:text-amber-300 text-xs">Awaiting file / processing</span>
                                @elseif ($item->kind === 'primary' && $isPendingFile && $tmpReady)
                                    <span class="text-sky-700 dark:text-sky-300 text-xs">Processing queue</span>
                                @elseif ($p->photo_approved === false && empty($p->photo_rejected_at) && $item->kind === 'primary')
                                    <span class="text-amber-700 dark:text-amber-300 text-xs">Primary pending review</span>
                                @elseif ($item->kind === 'gallery')
                                    <span class="text-amber-700 dark:text-amber-300 text-xs">Gallery pending</span>
                                @else
                                    <span class="text-gray-500 text-xs">—</span>
                                @endif
                            </td>
                            @php
                                $scan = $item->kind === 'primary'
                                    ? ($p->photo_moderation_snapshot ?? $item->primaryGalleryModerationScan ?? null)
                                    : ($gp?->moderation_scan_json ?? null);
                            @endphp
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-300 text-xs align-top">
                                @if (is_array($scan) && ! empty($scan))
                                    <div class="space-y-0.5 font-mono leading-snug">
                                        <div><span class="text-gray-500 dark:text-gray-400">API:</span> {{ $scan['api_status'] ?? '—' }}</div>
                                        <div><span class="text-gray-500 dark:text-gray-400">Pipeline:</span> {{ ($scan['pipeline_safe'] ?? false) ? 'safe' : 'flagged' }} @if (isset($scan['pipeline_confidence'])) ({{ number_format((float) $scan['pipeline_confidence'], 4) }}) @endif</div>
                                        @if (! empty($scan['detections']) && is_array($scan['detections']))
                                            <div class="text-gray-500 dark:text-gray-400 mt-1">Detections:</div>
                                            <ul class="list-disc pl-4 text-[11px] text-gray-700 dark:text-gray-200 max-w-xs">
                                                @foreach (array_slice($scan['detections'], 0, 6) as $det)
                                                    <li>{{ $det['class'] ?? '?' }} @if (isset($det['score'])) · {{ number_format((float) $det['score'], 4) }} @endif</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @elseif ($item->kind === 'primary' && $isPendingFile && ! $tmpReady)
                                    <p class="text-amber-800 dark:text-amber-200/90 text-[11px] leading-snug max-w-[14rem]">
                                        NudeNet scores show <strong>after</strong> the upload job runs: the API scans the saved file, then this column and <code class="text-[10px]">photo_moderation_snapshot</code> are filled.
                                    </p>
                                    <p class="text-gray-500 dark:text-gray-400 text-[10px] mt-1">If this stays empty a long time, check the queue worker: <code class="text-[10px]">php artisan queue:work</code></p>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $gp?->updated_at?->format('Y-m-d H:i') ?? $p->updated_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-3">
                                @if ($item->kind === 'primary')
                                    <form method="post" action="{{ route('admin.profiles.approve-image', $p) }}" class="space-y-2">
                                        @csrf
                                        <input type="hidden" name="return_to" value="photo-review-queue">
                                        <textarea
                                            name="reason"
                                            rows="2"
                                            required
                                            minlength="10"
                                            maxlength="2000"
                                            class="w-full min-w-[12rem] max-w-xs text-xs rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1.5"
                                            placeholder="Reason (min 10 characters)"
                                        ></textarea>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button type="submit" formaction="{{ route('admin.profiles.approve-image', $p) }}" formmethod="post" class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700">Approve</button>
                                            <button type="submit" formaction="{{ route('admin.profiles.reject-image', $p) }}" formmethod="post" class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium bg-amber-600 text-white hover:bg-amber-700">Reject</button>
                                            <button type="submit" formaction="{{ route('admin.profiles.delete-primary-photo', $p) }}" formmethod="post" class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium bg-red-600 text-white hover:bg-red-700" onclick="return confirm('Remove this primary photo from the profile?');">Delete</button>
                                        </div>
                                    </form>
                                @else
                                    <form method="post" action="{{ route('admin.profile-photos.approve', $gp) }}" class="space-y-2">
                                        @csrf
                                        <input type="hidden" name="return_to" value="photo-review-queue">
                                        <textarea
                                            name="reason"
                                            rows="2"
                                            required
                                            minlength="10"
                                            maxlength="2000"
                                            class="w-full min-w-[12rem] max-w-xs text-xs rounded-md border border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-2 py-1.5"
                                            placeholder="Reason (min 10 characters)"
                                        ></textarea>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button type="submit" formaction="{{ route('admin.profile-photos.approve', $gp) }}" formmethod="post" class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium bg-emerald-600 text-white hover:bg-emerald-700">Approve</button>
                                            <button type="submit" formaction="{{ route('admin.profile-photos.reject', $gp) }}" formmethod="post" class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium bg-amber-600 text-white hover:bg-amber-700">Reject</button>
                                            <button type="submit" formaction="{{ route('admin.profile-photos.delete', $gp) }}" formmethod="post" class="inline-flex items-center px-2.5 py-1 rounded text-xs font-medium bg-red-600 text-white hover:bg-red-700" onclick="return confirm('Remove this gallery photo?');">Delete</button>
                                        </div>
                                    </form>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.profiles.show', $p->id) }}" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-medium whitespace-nowrap">Open profile →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $items->links() }}</div>
    @endif
</div>
@endsection
