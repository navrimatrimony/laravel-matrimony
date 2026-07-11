@props([
    'current' => 'form',
])

@php
    $steps = [
        'form' => ['label' => 'माहिती', 'number' => 1],
        'photo' => ['label' => 'फोटो', 'number' => 2],
        'preferences' => ['label' => 'प्राधान्ये', 'number' => 3],
        'email' => ['label' => 'ईमेल', 'number' => 4],
        'password' => ['label' => 'पासवर्ड', 'number' => 5],
        'done' => ['label' => 'पूर्ण', 'number' => 6],
    ];
    $currentNumber = $steps[$current]['number'] ?? 1;
@endphp

<nav aria-label="नोंदणी प्रगती" class="mb-4">
    <ol class="flex items-center justify-between gap-1 sm:gap-2">
        @foreach ($steps as $key => $step)
            @php
                $isCurrent = $key === $current;
                $isComplete = $step['number'] < $currentNumber;
            @endphp
            <li class="flex min-w-0 flex-1 flex-col items-center text-center">
                <span @class([
                    'flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold ring-2',
                    'bg-violet-600 text-white ring-violet-600' => $isCurrent,
                    'bg-emerald-500 text-white ring-emerald-500' => $isComplete,
                    'bg-white text-gray-500 ring-gray-200' => ! $isCurrent && ! $isComplete,
                ])>
                    @if ($isComplete)
                        ✓
                    @else
                        {{ $step['number'] }}
                    @endif
                </span>
                <span @class([
                    'mt-1 hidden truncate text-[10px] font-medium sm:block',
                    'text-violet-700' => $isCurrent,
                    'text-emerald-700' => $isComplete,
                    'text-gray-500' => ! $isCurrent && ! $isComplete,
                ])>{{ $step['label'] }}</span>
            </li>
            @if (! $loop->last)
                <li aria-hidden="true" @class([
                    'mb-5 h-0.5 flex-1 rounded-full',
                    $isComplete ? 'bg-emerald-300' : 'bg-gray-200',
                ])></li>
            @endif
        @endforeach
    </ol>
</nav>
