{{-- Modal fragment: text-first; thumbnail is small reference only (full image on detail page). --}}
@php($ex = $aiExplain ?? ['summary' => '', 'risk_badge' => '—'])
<div class="text-gray-900 dark:text-gray-100">
    <div class="flex flex-wrap items-start gap-3 border-b border-gray-200 dark:border-gray-600 pb-3 mb-4">
        <a href="{{ route('admin.photo-moderation.show', $photo) }}" class="shrink-0 block rounded-md border border-gray-200 dark:border-gray-600 overflow-hidden bg-gray-100 dark:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500" title="Open full page for larger preview">
            <img src="{{ $previewUrl }}" alt="" width="72" height="72" class="w-[72px] h-[72px] object-cover" loading="lazy">
        </a>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-lg font-semibold leading-tight">Photo #{{ $photo->id }}</h2>
                    @if ($photo->profile)
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                            Profile <a href="{{ route('admin.profiles.show', $photo->profile) }}" class="text-indigo-600 dark:text-indigo-400 underline" target="_blank" rel="noopener">{{ $photo->profile->id }}</a>
                            · User {{ $photo->profile->user_id ?? '—' }}
                        </p>
                    @endif
                </div>
                <a href="{{ route('admin.photo-moderation.show', $photo) }}" class="text-sm text-indigo-600 dark:text-indigo-400 underline shrink-0" target="_blank" rel="noopener">Full page →</a>
            </div>
        </div>
    </div>

    <div class="grid gap-3 mb-4 md:grid-cols-2">
        <div class="rounded-lg border border-gray-200 dark:border-gray-600 p-3 md:p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Scan</p>
            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
                <dt class="text-gray-500 dark:text-gray-400">api_status</dt><dd class="font-mono font-medium">{{ $headline['api_status'] ?? '—' }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">Confidence</dt><dd class="font-medium">{{ $headline['confidence_pct'] !== null ? $headline['confidence_pct'].'%' : '—' }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">Detections</dt><dd class="font-medium">{{ $headline['detection_count'] ?? 0 }}</dd>
                <dt class="text-gray-500 dark:text-gray-400">Unsafe lock</dt><dd class="font-medium">{{ $unsafe ? 'Yes' : 'No' }}</dd>
            </dl>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-600 p-3 md:p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">AI rationale</p>
            <p class="text-sm md:text-base leading-relaxed text-gray-800 dark:text-gray-100">{{ $ex['summary'] ?? '—' }}</p>
            <span class="inline-flex mt-3 rounded-md px-2.5 py-1 text-xs font-bold uppercase tracking-wide
                @if (($ex['risk_badge'] ?? '') === 'HIGH RISK') bg-rose-600 text-white
                @elseif (($ex['risk_badge'] ?? '') === 'MEDIUM RISK') bg-amber-500 text-gray-900
                @elseif (($ex['risk_badge'] ?? '') === 'LOW RISK') bg-emerald-600 text-white
                @else bg-gray-200 text-gray-800 dark:bg-gray-700 dark:text-gray-200 @endif">{{ $ex['risk_badge'] ?? '—' }}</span>
        </div>
    </div>

    <div class="mb-4">
        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Audit trail</h3>
        <ul class="space-y-2 text-sm border border-gray-200 dark:border-gray-600 rounded-lg p-3 md:p-4 bg-gray-50 dark:bg-gray-900/40">
            @foreach ($timeline as $row)
                <li class="flex gap-2">
                    <span class="shrink-0 w-2 h-2 mt-2 rounded-full
                        @if ($row['kind'] === 'ai') bg-violet-500
                        @elseif ($row['kind'] === 'admin') bg-indigo-500
                        @else bg-gray-400 @endif"></span>
                    <div class="min-w-0 leading-relaxed">
                        <span class="text-gray-800 dark:text-gray-200">{{ $row['text'] }}</span>
                        @if (! empty($row['at']))
                            <span class="text-gray-500 dark:text-gray-400 ml-1 text-xs">({{ $row['at'] }})</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    @if (count($detections) > 0)
        <div>
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-2">Detections</h3>
            <div class="max-h-52 md:max-h-64 overflow-y-auto text-sm font-mono border border-gray-200 dark:border-gray-600 rounded-lg p-3 leading-relaxed">
                @foreach ($detections as $d)
                    <div class="py-1 border-b border-gray-100 dark:border-gray-700 last:border-0">{{ $d['class'] ?? '—' }} — max {{ $d['max_score_pct'] !== null ? $d['max_score_pct'].'%' : '—' }} (×{{ $d['box_count'] ?? 0 }})</div>
                @endforeach
            </div>
        </div>
    @endif
</div>
