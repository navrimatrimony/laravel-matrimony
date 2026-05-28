@extends('layouts.app')

@section('content')
@php
    $summary = $summary ?? ['engine_enabled' => false, 'invited' => 0, 'converted' => 0, 'referrals_done' => 0, 'rewards_earned' => 0, 'pending_claim' => 0];
    $entries = $entries ?? [];
@endphp
<div class="min-h-[60vh] bg-gradient-to-b from-rose-50/70 via-white to-violet-50/50 dark:from-gray-950 dark:via-gray-900 dark:to-gray-950 py-8 sm:py-10">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">
        <header class="mb-6">
            <a href="{{ route('dashboard') }}" class="text-sm font-medium text-rose-600 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300">← {{ __('nav.dashboard') }}</a>
            <h1 class="mt-3 text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50">{{ __('referrals.title') }}</h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">{{ __('referrals.subtitle') }}</p>
        </header>

        <x-referred-registration-welcome :welcome="$referredRegistrationWelcome ?? null" class="mb-6" />

        @if (! ($summary['engine_enabled'] ?? false))
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white/80 px-6 py-10 text-center dark:border-gray-600 dark:bg-gray-800/60">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('referrals.engine_off') }}</p>
            </div>
        @else
            @if (! empty($referralShareTools))
                <div class="mb-6 rounded-2xl border border-rose-200/80 bg-white/95 p-4 sm:p-5 shadow-sm dark:border-rose-900/40 dark:bg-gray-800/95">
                    <p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ __('referrals.share_title') }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('referrals.share_whatsapp_hint') }}</p>
                    <x-referral-share-tools :share-tools="$referralShareTools" id-prefix="referrals-page" class="mt-3" />
                </div>
            @endif

            <div class="mb-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="rounded-xl border border-gray-200/90 bg-white/90 px-3 py-3 text-center dark:border-gray-700 dark:bg-gray-800/90">
                    <p class="text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-50">{{ (int) ($summary['invited'] ?? 0) }}</p>
                    <p class="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('referrals.stat_invited') }}</p>
                </div>
                <div class="rounded-xl border border-gray-200/90 bg-white/90 px-3 py-3 text-center dark:border-gray-700 dark:bg-gray-800/90">
                    <p class="text-2xl font-bold tabular-nums text-violet-700 dark:text-violet-300">{{ (int) ($summary['converted'] ?? 0) }}</p>
                    <p class="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('referrals.stat_converted') }}</p>
                </div>
                <div class="rounded-xl border border-gray-200/90 bg-white/90 px-3 py-3 text-center dark:border-gray-700 dark:bg-gray-800/90">
                    <p class="text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ (int) ($summary['referrals_done'] ?? $summary['rewards_earned'] ?? 0) }}</p>
                    <p class="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('referrals.stat_rewards') }}</p>
                </div>
                <div class="rounded-xl border border-violet-200/90 bg-violet-50/80 px-3 py-3 text-center dark:border-violet-800 dark:bg-violet-950/30">
                    <p class="text-2xl font-bold tabular-nums text-violet-800 dark:text-violet-200">{{ (int) ($summary['pending_claim'] ?? 0) }}</p>
                    <p class="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-violet-600/90 dark:text-violet-300/90">{{ __('referrals.stat_pending') }}</p>
                </div>
            </div>

            <x-referral-monthly-cap-progress :progress="$summary['monthly_cap_progress'] ?? null" class="mb-6" />

            @php
                $bonusProof = $referralBonusProof ?? ['has_active_plan' => false, 'paid_until' => null, 'carry_lines' => [], 'rewards_applied_count' => 0];
            @endphp
            @if ((int) ($bonusProof['rewards_applied_count'] ?? 0) > 0)
                <div class="mb-6 rounded-2xl border border-sky-200/90 bg-sky-50/80 px-4 py-4 dark:border-sky-900/50 dark:bg-sky-950/30">
                    <p class="text-sm font-semibold text-sky-900 dark:text-sky-100">{{ __('referrals.reward_proof_heading') }}</p>
                    <p class="mt-1 text-xs text-sky-800/90 dark:text-sky-200/90">{{ __('referrals.reward_proof_intro') }}</p>
                    @if ($bonusProof['has_active_plan'] ?? false)
                        @if (! empty($bonusProof['paid_until']))
                            <p class="mt-2 text-sm text-sky-900 dark:text-sky-100">
                                <span class="font-medium">{{ __('referrals.reward_proof_paid_until') }}</span>
                                {{ $bonusProof['paid_until'] }}
                            </p>
                        @endif
                        @if (! empty($bonusProof['carry_lines']))
                            <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-sky-800/80 dark:text-sky-200/80">{{ __('referrals.reward_proof_carry_heading') }}</p>
                            <ul class="mt-1 list-disc list-inside text-sm text-sky-900 dark:text-sky-100 space-y-0.5">
                                @foreach ($bonusProof['carry_lines'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <p class="mt-2 text-xs text-sky-800/90 dark:text-sky-200/90">{{ __('referrals.reward_proof_usage_note') }}</p>
                        <a href="{{ route('user.my-plan') }}" class="mt-2 inline-flex text-sm font-semibold text-sky-700 underline dark:text-sky-300">{{ __('referrals.reward_proof_my_plan_link') }}</a>
                    @else
                        <p class="mt-2 text-sm text-sky-900 dark:text-sky-100">{{ __('referrals.reward_proof_no_active_plan') }}</p>
                    @endif
                </div>
            @endif

            @if (($referralPendingClaimCount ?? 0) > 0)
                <div class="mb-6 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 dark:border-violet-800 dark:bg-violet-950/40">
                    <p class="text-sm text-violet-900 dark:text-violet-100">{{ __('dashboard.referral_pending_claim_title') }}</p>
                    <a href="{{ route('plans.index') }}" class="mt-2 inline-flex text-sm font-semibold text-violet-700 underline dark:text-violet-300">{{ __('referrals.view_plans_claim') }}</a>
                </div>
            @endif

            @php
                $rules = $referralRules ?? ['monthly_cap' => 0, 'paid_plans_only' => true];
            @endphp
            <details class="mb-6 rounded-2xl border border-gray-200/90 bg-white/95 dark:border-gray-700 dark:bg-gray-800/95 group">
                <summary class="cursor-pointer list-none px-4 py-3 sm:px-5 flex items-center justify-between gap-2">
                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('referrals.rules_heading') }}</span>
                    <span class="text-gray-400 group-open:rotate-180 transition-transform" aria-hidden="true">▾</span>
                </summary>
                <div class="border-t border-gray-200/80 px-4 py-4 sm:px-5 text-sm text-gray-600 dark:text-gray-300 space-y-2 dark:border-gray-700">
                    <p>{{ __('referrals.rules_step_link') }}</p>
                    <p>{{ __('referrals.rules_step_first_paid') }}</p>
                    @if ($rules['paid_plans_only'] ?? true)
                        <p>{{ __('referrals.rules_step_paid_only') }}</p>
                    @endif
                    <p>{{ __('referrals.rules_step_reward') }}</p>
                    <p>{{ __('referrals.rules_step_pending') }}</p>
                    @if ((int) ($rules['monthly_cap'] ?? 0) > 0)
                        <p>{{ __('referrals.rules_step_monthly_cap', ['cap' => (int) $rules['monthly_cap']]) }}</p>
                    @endif
                    @if ((int) ($rules['pending_claim_expiry_days'] ?? 0) > 0)
                        <p>{{ __('referrals.rules_step_pending_expiry', ['days' => (int) $rules['pending_claim_expiry_days']]) }}</p>
                    @endif
                    @if (! empty($rules['quality_gates_enabled']))
                        <p>{{ __('referrals.rules_step_quality_gates') }}</p>
                    @endif
                    @if (! empty($rules['referred_checkout_enabled']))
                        @php
                            $rcPct = (int) ($rules['referred_checkout_percent'] ?? 0);
                            $rcDays = (int) ($rules['referred_checkout_extra_days'] ?? 0);
                        @endphp
                        @if ($rcPct > 0 && $rcDays > 0)
                            <p>{{ __('referrals.rules_step_referred_checkout_both', ['percent' => $rcPct, 'days' => $rcDays]) }}</p>
                        @elseif ($rcPct > 0)
                            <p>{{ __('referrals.rules_step_referred_checkout', ['percent' => $rcPct]) }}</p>
                        @elseif ($rcDays > 0)
                            <p>{{ __('referrals.rules_step_referred_checkout_days', ['days' => $rcDays]) }}</p>
                        @endif
                    @endif
                    @if (! empty($rules['fraud_auto_hold']))
                        <p>{{ __('referrals.rules_step_fraud_hold') }}</p>
                    @endif
                    <p>{{ __('referrals.rules_step_share') }}</p>
                    @if (! empty($rules['renewal_micro_bonus_enabled']) && (int) ($rules['renewal_micro_bonus_days'] ?? 0) > 0)
                        <p>{{ __('referrals.rules_step_renewal_micro', ['days' => (int) $rules['renewal_micro_bonus_days']]) }}</p>
                    @endif
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('referrals.rules_note_fraud') }}</p>
                </div>
            </details>

            <div class="rounded-2xl border border-gray-200/90 bg-white/95 shadow-sm dark:border-gray-700 dark:bg-gray-800/95 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200/80 dark:border-gray-700">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('referrals.list_heading') }}</h2>
                </div>

                @if ($entries === [])
                    <div class="px-6 py-12 text-center">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('referrals.empty_title') }}</p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 max-w-md mx-auto">{{ __('referrals.empty_body') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($entries as $entry)
                            @php
                                $stage = (string) ($entry['stage'] ?? 'registered');
                                $stageTone = match ($stage) {
                                    'reward_earned' => 'bg-emerald-50 text-emerald-800 border-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-100 dark:border-emerald-900/40',
                                    'upgraded' => 'bg-violet-50 text-violet-800 border-violet-100 dark:bg-violet-950/40 dark:text-violet-100 dark:border-violet-900/40',
                                    'pending_claim' => 'bg-violet-50 text-violet-800 border-violet-100 dark:bg-violet-950/40 dark:text-violet-100 dark:border-violet-900/40',
                                    'profile_active' => 'bg-sky-50 text-sky-800 border-sky-100 dark:bg-sky-950/40 dark:text-sky-100 dark:border-sky-900/40',
                                    'review_pending' => 'bg-amber-50 text-amber-900 border-amber-200 dark:bg-amber-950/40 dark:text-amber-100 dark:border-amber-900/40',
                                    'review_rejected' => 'bg-rose-50 text-rose-800 border-rose-100 dark:bg-rose-950/40 dark:text-rose-100 dark:border-rose-900/40',
                                    'cap_skipped' => 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600',
                                    'pending_expired', 'admin_cancelled', 'reward_revoked' => 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600',
                                    default => 'bg-amber-50 text-amber-900 border-amber-100 dark:bg-amber-950/40 dark:text-amber-100 dark:border-amber-900/40',
                                };
                                $hint = $entry['reward_hint'] ?? null;
                            @endphp
                            <li class="px-4 py-4 sm:px-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $entry['display_name'] }}</p>
                                        @if (! empty($entry['joined_at']))
                                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                {{ __('referrals.joined', ['date' => $entry['joined_at']->format('d M Y')]) }}
                                            </p>
                                        @endif
                                        @if ($stage === 'cap_skipped')
                                            <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">{{ __('referrals.stage_cap_skipped_hint') }}</p>
                                        @elseif ($stage === 'quality_pending')
                                            <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">{{ __('referrals.stage_quality_pending_hint') }}</p>
                                        @elseif ($stage === 'pending_expired')
                                            <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">{{ __('referrals.stage_pending_expired_hint') }}</p>
                                        @elseif ($stage === 'upgraded')
                                            <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">{{ __('referrals.stage_upgraded_hint') }}</p>
                                        @elseif (! empty($entry['reward_detail_lines']))
                                            <div class="mt-2 rounded-lg border border-emerald-200/90 bg-emerald-50/90 px-3 py-2.5 dark:border-emerald-900/50 dark:bg-emerald-950/35">
                                                <p class="text-[11px] font-bold uppercase tracking-wide text-emerald-800 dark:text-emerald-200">
                                                    {{ in_array($stage, ['reward_earned'], true)
                                                        ? __('referrals.reward_received_heading')
                                                        : __('referrals.reward_waiting_heading') }}
                                                </p>
                                                @if (! empty($entry['reward_plan_name']))
                                                    <p class="mt-1 text-xs text-emerald-900/90 dark:text-emerald-100/90">
                                                        {{ __('referrals.reward_plan_context', ['plan' => $entry['reward_plan_name']]) }}
                                                    </p>
                                                @endif
                                                <ul class="mt-1.5 list-disc list-inside space-y-0.5 text-xs text-emerald-900 dark:text-emerald-100">
                                                    @foreach ($entry['reward_detail_lines'] as $line)
                                                        <li>{{ $line }}</li>
                                                    @endforeach
                                                </ul>
                                                @if ($stage === 'reward_earned' && ! empty($entry['reward_applied_at']))
                                                    <p class="mt-1.5 text-[11px] text-emerald-800/90 dark:text-emerald-200/90">
                                                        {{ __('referrals.reward_applied_on', ['date' => $entry['reward_applied_at']->timezone(config('app.timezone'))->format('d M Y, H:i')]) }}
                                                    </p>
                                                @endif
                                                @if ($stage === 'reward_earned')
                                                    <p class="mt-1.5 text-[11px] text-emerald-800/90 dark:text-emerald-200/90">{{ __('referrals.reward_proof_row_hint') }}</p>
                                                @endif
                                                @if ($stage === 'pending_claim')
                                                    <p class="mt-1.5 text-[11px] text-violet-800 dark:text-violet-200">{{ __('referrals.reward_claim_note') }}</p>
                                                @endif
                                            </div>
                                        @elseif ($hint && $hint !== 'applied' && is_numeric($hint))
                                            <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">
                                                {{ in_array($stage, ['pending_claim', 'upgraded', 'quality_pending', 'cap_skipped'], true)
                                                    ? __('referrals.reward_days_pending', ['days' => (int) $hint])
                                                    : __('referrals.reward_days_earned', ['days' => (int) $hint]) }}
                                            </p>
                                        @elseif ($stage === 'reward_earned')
                                            <p class="mt-1.5 text-xs text-gray-600 dark:text-gray-400">{{ __('referrals.reward_applied_generic') }}</p>
                                        @endif
                                    </div>
                                    <span class="inline-flex shrink-0 items-center rounded-lg border px-2.5 py-1 text-xs font-semibold leading-snug {{ $stageTone }}">
                                        {{ __('referrals.stage_'.$stage) }}
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>
</div>

@endsection
