@php
    $trackerSteps = collect($steps ?? []);
    $trackerCurrentKey = (string) ($currentStepKey ?? ($trackerSteps->first()['key'] ?? 'registration'));
    $trackerCurrentIndex = $trackerSteps->search(fn ($step) => ($step['key'] ?? null) === $trackerCurrentKey);
    $trackerCurrentIndex = $trackerCurrentIndex === false ? 0 : $trackerCurrentIndex;
    $trackerCompletedIndex = -1;
    foreach ($trackerSteps as $stepIndex => $trackerStep) {
        if (($trackerStep['state'] ?? null) !== 'complete') {
            break;
        }
        $trackerCompletedIndex = $stepIndex;
    }
    $trackerProgressPercent = $trackerSteps->count() > 1 && $trackerCompletedIndex > 0
        ? (int) round(($trackerCompletedIndex / ($trackerSteps->count() - 1)) * 100)
        : 0;
@endphp

@once
    <style>
        [data-suchak-progress-list] { grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 768px) {
            [data-suchak-progress-list] {
                grid-template-columns: repeat(var(--suchak-step-count), minmax(0, 1fr));
            }
        }
    </style>
@endonce

<div class="relative mt-8">
    <div class="absolute left-8 right-8 top-5 hidden h-1 rounded-full bg-gray-200 dark:bg-gray-700 md:block"></div>
    <div class="absolute left-8 top-5 hidden h-1 rounded-full bg-emerald-600 md:block" style="width: calc((100% - 4rem) * {{ $trackerProgressPercent }} / 100);"></div>
    <ol
        class="relative grid gap-4"
        style="--suchak-step-count: {{ max(1, $trackerSteps->count()) }};"
        data-suchak-progress-list
        data-suchak-progress-current="{{ $trackerCurrentKey }}"
    >
        @foreach ($trackerSteps as $index => $step)
            @php
                $circleClass = match ($step['state']) {
                    'complete' => 'border-emerald-600 bg-emerald-600 text-white',
                    'submitted' => 'border-blue-600 bg-blue-600 text-white',
                    'in_progress' => 'border-blue-600 bg-blue-600 text-white',
                    'current' => 'border-gray-950 bg-gray-950 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-950',
                    'blocked' => 'border-red-600 bg-red-600 text-white',
                    default => 'border-gray-300 bg-white text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300',
                };
                $labelClass = match ($step['state']) {
                    'complete' => 'text-emerald-700 dark:text-emerald-300',
                    'submitted' => 'text-blue-700 dark:text-blue-300',
                    'in_progress' => 'text-blue-700 dark:text-blue-300',
                    'current' => 'text-gray-950 dark:text-gray-100',
                    'blocked' => 'text-red-700 dark:text-red-300',
                    default => 'text-gray-500 dark:text-gray-400',
                };
                $showAction = filled($step['action_label'] ?? null)
                    && filled($step['action_url'] ?? null)
                    && in_array($step['state'], ['current', 'submitted', 'in_progress', 'blocked'], true);
            @endphp
            <li class="min-w-0">
                <div class="flex items-start gap-3 md:block">
                    <div class="relative z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 text-sm font-bold {{ $circleClass }}">
                        @if ($step['state'] === 'complete')
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.31a1 1 0 0 1-1.42 0l-3.25-3.28a1 1 0 1 1 1.42-1.408l2.54 2.562 6.54-6.592a1 1 0 0 1 1.414-.006Z" clip-rule="evenodd" />
                            </svg>
                        @elseif ($step['state'] === 'blocked')
                            !
                        @else
                            {{ $index + 1 }}
                        @endif
                    </div>
                    <div class="min-w-0 md:mt-3">
                        <p class="text-sm font-bold {{ $labelClass }}">{{ $step['label'] }}</p>
                        <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</p>
                        @if ($showAction)
                            <a href="{{ $step['action_url'] }}" class="mt-3 inline-flex rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">
                                {{ $step['action_label'] }}
                            </a>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ol>
</div>
