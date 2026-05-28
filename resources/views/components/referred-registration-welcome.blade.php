@props(['welcome'])

@if (! empty($welcome) && is_array($welcome))
    @php
        $pct = (int) ($welcome['percent_off'] ?? 0);
        $extra = (int) ($welcome['extra_days'] ?? 0);
        if ($pct > 0 && $extra > 0) {
            $body = __('referrals.registration_welcome_body_both', ['percent' => $pct, 'days' => $extra]);
        } elseif ($pct > 0) {
            $body = __('referrals.registration_welcome_body_percent', ['percent' => $pct]);
        } elseif ($extra > 0) {
            $body = __('referrals.registration_welcome_body_days', ['days' => $extra]);
        } else {
            $body = null;
        }
    @endphp
    @if ($body !== null)
        <div {{ $attributes->merge(['class' => 'mb-6 rounded-2xl border border-violet-300/80 bg-gradient-to-r from-violet-50 via-indigo-50/90 to-rose-50/80 p-4 shadow-sm dark:border-violet-700/60 dark:from-violet-950/50 dark:via-indigo-950/40 dark:to-rose-950/30']) }} role="status">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-bold text-violet-900 dark:text-violet-100">{{ __('referrals.registration_welcome_title') }}</p>
                    @if (! empty($welcome['referrer_display_name']))
                        <p class="mt-1 text-sm font-medium text-violet-800 dark:text-violet-200">
                            {{ __('referrals.registration_welcome_invited_by', ['name' => $welcome['referrer_display_name']]) }}
                        </p>
                    @endif
                    <p class="mt-1 text-sm text-violet-800/95 dark:text-violet-200/90">{{ $body }}</p>
                    <p class="mt-2 text-xs text-violet-700/90 dark:text-violet-300/80">{{ __('referrals.registration_welcome_note') }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:items-end">
                    <a href="{{ $welcome['plans_url'] ?? route('plans.index') }}" class="inline-flex items-center rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700 dark:bg-violet-500 dark:hover:bg-violet-600">
                        {{ __('referrals.registration_welcome_cta_plans') }}
                    </a>
                    <form method="post" action="{{ $welcome['dismiss_url'] ?? route('referrals.welcome.dismiss') }}">
                        @csrf
                        <button type="submit" class="text-xs font-medium text-violet-700/90 underline decoration-violet-400/60 underline-offset-2 hover:text-violet-900 dark:text-violet-300 dark:hover:text-violet-100">
                            {{ __('referrals.registration_welcome_dismiss') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endif
